<?php

declare(strict_types=1);

namespace Trilobit\Core\Finance;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\ConstraintViolationException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Trilobit\Core\Domain\Finance\TaxRate;
use Trilobit\Core\Domain\Finance\TaxRateKind;
use Trilobit\Core\Domain\Finance\VatRate;
use Trilobit\Core\Event\EntityChanged;
use Trilobit\Core\Security\Doorkeeper;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;
use Trilobit\Core\Tenancy\Tenancy;

/**
 * The rates of tax of the business this request is in, changed - and every
 * rule about changing them, in one place.
 *
 * **Every way in comes through here**, as with Trilobit\Core\Security\People:
 * a page, a form posted by hand, a screen nobody has written yet. So nothing
 * here trusts that whoever calls it asked first. Each act asks for its own
 * privilege on Trilobit\Core\Security\Resource::TaxRate - adding, changing and
 * archiving are three rights, not one; restoring an archived rate is the undo
 * of archiving and asks what archiving asks, and taking rates from a template
 * is adding them and asks what adding asks - through
 * Trilobit\Core\Security\Doorkeeper, spelled out as the two enums.
 *
 * **A business that has rates has exactly one default, and it is not
 * archived.** The database holds that there is never a second and that the
 * default is never archived (see TaxRate); this holds that there is never
 * none: the first rate a business adds is made its default, and the default
 * moves only by another rate being made it - in one transaction, the old one
 * let go before the new one is taken, so the unique index never sees two.
 * What this reads before writing - whether there is a default yet, whether a
 * rate is the default or archived - says what to answer, and the database
 * says whether the write stands: two requests that read the same rows and
 * write against each other cannot both win. The one that loses is refused as
 * a TaxRateConflict, in a sentence rather than with the database's error, and
 * nothing it wrote is kept.
 *
 * **An archived rate is kept as it was.** It is no longer offered for anything
 * new, it cannot be made the default, and it is not changed until it is
 * restored. Nothing points at a rate yet - the shop's products still carry a
 * rate of their own - so this is what a rate will keep for whatever is priced
 * at it once something is.
 *
 * **A change of the rate itself is written to the audit trail** - Core's own
 * EntityChanged, carried by its dispatcher to
 * Trilobit\Core\Event\AuditListener, as RATE_CHANGED - in the transaction the
 * change is written in, so that the two are kept together or not at all. It
 * is the change somebody will later ask about, because it will change what
 * everything priced at the rate costs with tax. The trail keeps which rate and
 * when, and nothing more: an entry of it has no room for the old and the new
 * number or for who made the change (Trilobit\Core\Domain\Audit\AuditEntry).
 *
 * The business is never an argument. It is the one this request is in -
 * Trilobit\Core\Tenancy\Tenancy - and every read of a rate goes through the
 * tenant filter, so a rate of another business is not found rather than found
 * and refused.
 */
final readonly class TaxRates
{
    /** What the audit trail calls a change of the rate of a TaxRate. */
    public const string RATE_CHANGED = 'rate_changed';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Doorkeeper $doorkeeper,
        private Tenancy $tenancy,
        private EventDispatcherInterface $dispatcher,
        private TaxRateTemplates $templates,
    ) {}

    public function find(int $id): ?TaxRate
    {
        return $this->entityManager->find(TaxRate::class, $id);
    }

    /**
     * The rates that may be chosen for something new: every rate that is not
     * archived, in their order.
     *
     * @return list<TaxRate>
     */
    public function offered(): array
    {
        $rates = $this->entityManager
            ->createQuery(sprintf(
                'SELECT r FROM %s r WHERE r.archivedAt IS NULL ORDER BY r.position ASC, r.id ASC',
                TaxRate::class,
            ))
            ->getResult();

        return array_values(array_filter(is_array($rates) ? $rates : [], static fn(mixed $rate): bool => $rate instanceof TaxRate));
    }

    /** The rate something new starts with, or null while the business has none. */
    public function default(): ?TaxRate
    {
        return $this->entityManager->getRepository(TaxRate::class)->findOneBy(['isDefault' => true]);
    }

    /**
     * A new rate, after every other; the first of the business becomes its
     * default.
     *
     * @throws TaxRateRefused
     */
    public function add(string $name, VatRate $rate, ?string $country, ?TaxRateKind $kind): TaxRate
    {
        $this->refuseUnless($this->doorkeeper->mayDo(Resource::TaxRate, Privilege::Add), 'add rates of tax');

        $added = new TaxRate(
            $this->tenancy->tenant(),
            $this->nameOf($name),
            $rate,
            $this->countryOf($country),
            $kind,
            $this->nextPosition(),
            $this->now(),
        );
        if (!$this->default() instanceof TaxRate) {
            $added->makeDefault($this->now());
        }

        // A rate made by hand has no template key, so the default is the only
        // thing unique about it, and a refusal is somebody else's first rate
        // saved in between.
        $this->written(function () use ($added): void {
            $this->entityManager->persist($added);
            $this->entityManager->flush();
        }, 'Somebody else added the first rate of this business at the same moment. Look at the rates again and add yours once more.');

        return $added;
    }

    /**
     * Rates of the templates (TaxRateTemplates), each made a rate of this
     * business under its template key, after every other and in the order
     * asked; the first of a business with no rate yet becomes its default, as
     * add() makes it. All of them are taken or none is.
     *
     * **A rate of a template is taken once.** One already among this
     * business's rates - archived or not - is refused rather than skipped: the
     * page offers only what adoptable() says, so asking for one is a form
     * posted by hand or by somebody who read the page before somebody else
     * took the rate. That two requests cannot both take it is the database's
     * to hold, by the unique index over the business and the template key
     * (TaxRate); the one that loses is refused as a TaxRateConflict.
     *
     * Taking a rate is adding one, and asks the same right.
     *
     * @param list<string> $templateKeys such as `cz.standard`; one asked twice is taken once
     *
     * @return list<TaxRate>
     *
     * @throws TaxRateRefused
     */
    public function adopt(array $templateKeys): array
    {
        $this->refuseUnless($this->doorkeeper->mayDo(Resource::TaxRate, Privilege::Add), 'add rates of tax');

        $chosen = [];
        foreach (array_unique($templateKeys) as $templateKey) {
            $chosen[] = $this->templates->rate($templateKey)
                ?? throw new TaxRateRefused(sprintf('No template has a rate called %s.', $templateKey));
        }

        if ($chosen === []) {
            throw new TaxRateRefused('Choose at least one rate of the template to add.');
        }

        $held = $this->adoptedKeys();
        foreach ($chosen as $rate) {
            if (in_array($rate->templateKey, $held, true)) {
                throw new TaxRateRefused(sprintf('%s of %s is among the rates of this business already.', $rate->label, $rate->country));
            }
        }

        $now = $this->now();
        $position = $this->nextPosition();
        $hasDefault = $this->default() instanceof TaxRate;
        $adopted = [];
        foreach ($chosen as $rate) {
            $made = new TaxRate($this->tenancy->tenant(), $rate->label, $rate->rate, $rate->country, $rate->kind, $position++, $now, $rate->templateKey);
            if (!$hasDefault && $adopted === []) {
                $made->makeDefault($now);
            }

            $adopted[] = $made;
        }

        $this->written(function () use ($adopted): void {
            foreach ($adopted as $made) {
                $this->entityManager->persist($made);
            }

            $this->entityManager->flush();
        }, 'Somebody else added rates of this business at the same moment - one of these from the template, or the first rate of all. Look at the rates again and choose once more.');

        return $adopted;
    }

    /**
     * The rates of $template this business has not taken yet, in the
     * template's order - what a page offers to adopt().
     *
     * @return list<TemplateRate>
     */
    public function adoptable(TaxRateTemplate $template): array
    {
        $held = $this->adoptedKeys();

        return array_values(array_filter(
            $template->rates,
            static fn(TemplateRate $rate): bool => !in_array($rate->templateKey, $held, true),
        ));
    }

    /**
     * Everything about the rate a person writes. A change of the rate itself
     * is written to the audit trail; see the class.
     *
     * @throws TaxRateRefused
     */
    public function revise(int $id, string $name, VatRate $rate, ?string $country, ?TaxRateKind $kind): TaxRate
    {
        $this->refuseUnless($this->doorkeeper->mayDo(Resource::TaxRate, Privilege::Edit), 'change rates of tax');
        $revised = $this->rateHere($id);
        if ($revised->isArchived()) {
            throw new TaxRateRefused(sprintf(
                '%s is archived, and an archived rate is kept as it was. Restore it first to change it.',
                $revised->name(),
            ));
        }

        $before = $revised->rate()->basisPoints();
        $name = $this->nameOf($name);
        $country = $this->countryOf($country);

        $this->entityManager->wrapInTransaction(function () use ($revised, $id, $before, $name, $rate, $country, $kind): void {
            $revised->revise($name, $rate, $country, $kind, $this->now());
            $this->entityManager->flush();

            if ($before !== $rate->basisPoints()) {
                $this->dispatcher->dispatch(new EntityChanged(TaxRate::class, (string) $id, self::RATE_CHANGED, $this->now()));
            }
        });

        return $revised;
    }

    /**
     * Makes $id the business's default and lets go of the one that was. See
     * the class for why the two happen in that order.
     *
     * @throws TaxRateRefused
     */
    public function makeDefault(int $id): void
    {
        $this->refuseUnless($this->doorkeeper->mayDo(Resource::TaxRate, Privilege::Edit), 'change which rate of tax is the default');
        $chosen = $this->rateHere($id);
        if ($chosen->isArchived()) {
            throw new TaxRateRefused(sprintf(
                '%s is archived, so nothing new is priced at it and it cannot be the default. Restore it first.',
                $chosen->name(),
            ));
        }

        if ($chosen->isDefault()) {
            return;
        }

        $this->written(function () use ($chosen): void {
            $was = $this->default();
            if ($was instanceof TaxRate) {
                $was->stopBeingDefault($this->now());
                $this->entityManager->flush();
            }

            $chosen->makeDefault($this->now());
            $this->entityManager->flush();
        }, sprintf(
            'Somebody else changed the rates at the same moment - %s was archived, or another rate made the default. Look at the rates again and choose once more.',
            $chosen->name(),
        ));
    }

    /**
     * Stops offering $id for anything new; whatever is priced at it keeps it.
     *
     * @throws TaxRateRefused
     */
    public function archive(int $id): void
    {
        $this->refuseUnless($this->doorkeeper->mayDo(Resource::TaxRate, Privilege::Delete), 'archive rates of tax');
        $archived = $this->rateHere($id);
        $refusal = $this->whyNotArchive($archived);
        if ($refusal !== null) {
            throw new TaxRateRefused($refusal);
        }

        $this->written(function () use ($archived): void {
            $archived->archive($this->now());
            $this->entityManager->flush();
        }, sprintf(
            'Somebody else made %s the default at the same moment, and the default cannot be archived. Look at the rates again.',
            $archived->name(),
        ));
    }

    /**
     * Offers an archived rate for something new again - the undo of
     * archive(), and asked for by the same right.
     *
     * @throws TaxRateRefused
     */
    public function restore(int $id): void
    {
        $this->refuseUnless($this->doorkeeper->mayDo(Resource::TaxRate, Privilege::Delete), 'restore rates of tax');
        $restored = $this->rateHere($id);
        if (!$restored->isArchived()) {
            throw new TaxRateRefused(sprintf('%s is not archived, so there is nothing to restore.', $restored->name()));
        }

        $restored->restore($this->now());
        $this->entityManager->flush();
    }

    /**
     * Why $rate could not be archived - null when it could, as far as the
     * rate is concerned. The one definition archive() and a page deciding
     * whether to draw the button use; the right is asked by each of them.
     */
    public function whyNotArchive(TaxRate $rate): ?string
    {
        if ($rate->isArchived()) {
            return sprintf('%s is archived already.', $rate->name());
        }

        if ($rate->isDefault()) {
            return sprintf(
                '%s is the default, and a business that has rates keeps a default. Make another rate the default first.',
                $rate->name(),
            );
        }

        return null;
    }

    /**
     * Runs $write in one transaction, and turns the database refusing it -
     * the unique index over the default or over the template key, or the
     * CHECK that the default is not archived - into a TaxRateConflict saying $conflict. The transaction is
     * rolled back, so nothing of $write is kept, and Doctrine closes the
     * entity manager; see TaxRateConflict for what a caller does then.
     *
     * @param callable(): void $write
     *
     * @throws TaxRateConflict
     */
    private function written(callable $write, string $conflict): void
    {
        try {
            $this->entityManager->wrapInTransaction($write);
        } catch (DriverException $refused) {
            // 23000 is what MariaDB says for a CHECK too, which DBAL has no
            // class of its own for.
            if (!$refused instanceof ConstraintViolationException && $refused->getSQLState() !== '23000') {
                throw $refused;
            }

            throw new TaxRateConflict($conflict, 0, $refused);
        }
    }

    /**
     * A rate of this business, or a refusal saying there is none by that
     * number - a rate of another business is not found, by the tenant filter.
     */
    private function rateHere(int $id): TaxRate
    {
        return $this->find($id) ?? throw new TaxRateRefused('This business has no rate of tax by that number.');
    }

    /** @throws TaxRateRefused */
    private function nameOf(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new TaxRateRefused('A rate of tax needs a name: it is what the rate is chosen by.');
        }

        if (mb_strlen($name) > TaxRate::MAX_NAME_LENGTH) {
            throw new TaxRateRefused(sprintf('A name is at most %d characters long.', TaxRate::MAX_NAME_LENGTH));
        }

        return $name;
    }

    /**
     * The country as two capital letters - meant as ISO 3166-1 alpha-2, and
     * not checked against its list - or null for none.
     *
     * @throws TaxRateRefused
     */
    private function countryOf(?string $country): ?string
    {
        $country = strtoupper(trim($country ?? ''));
        if ($country === '') {
            return null;
        }

        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new TaxRateRefused(sprintf(
                "'%s' is not a country: a country is its two letters, such as CZ, or nothing for a rate of no country.",
                $country,
            ));
        }

        return $country;
    }

    /** @return list<string> the template keys of every rate of this business taken from a template, archived ones included */
    private function adoptedKeys(): array
    {
        $keys = $this->entityManager
            ->createQuery(sprintf('SELECT r.templateKey FROM %s r WHERE r.templateKey IS NOT NULL', TaxRate::class))
            ->getSingleColumnResult();

        return array_values(array_filter($keys, is_string(...)));
    }

    private function nextPosition(): int
    {
        $last = $this->entityManager
            ->createQuery(sprintf('SELECT MAX(r.position) FROM %s r', TaxRate::class))
            ->getSingleScalarResult();

        return is_numeric($last) ? (int) $last + 1 : 1;
    }

    /**
     * The question is asked where each act is, spelled out as its two enums,
     * so that it is one Trilobit\Tests\Architecture\PermissionQuestions can
     * read; this only turns the answer into the refusal.
     */
    private function refuseUnless(bool $allowed, string $what): void
    {
        if (!$allowed) {
            throw new TaxRateRefused(sprintf('You may not %s.', $what));
        }
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
