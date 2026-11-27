<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Finance;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Nette\DI\Container;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\Finance\TaxRate;
use Trilobit\Core\Domain\Finance\TaxRateKind;
use Trilobit\Core\Domain\Finance\VatRate;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Finance\TaxRateConflict;
use Trilobit\Core\Finance\TaxRateRefused;
use Trilobit\Core\Finance\TaxRates;
use Trilobit\Core\Finance\TaxRateTemplates;
use Trilobit\Core\Finance\TemplateRate;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Grant;
use Trilobit\Core\Security\PermissionStructure;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * A business's rates of tax, changed through the one service that guards them.
 *
 * Every rule is asked of the service rather than of a page, because a page is
 * one way in and the service is every way. Somebody who keeps the rates and
 * nothing else does what the rules allow; somebody who may only see them, and
 * somebody who may only keep the content, are refused every change.
 */
#[CoversNothing]
final class TaxRatesTest extends TestCase
{
    /** Keeps the rates of tax and nothing else. */
    private const string KEEPER = 'fay@example.com';

    /** May see the rates and change nothing. */
    private const string VIEWER = 'vera@example.com';

    /** Keeps the content and holds nothing of the money matters. */
    private const string EDITOR = 'eddie@example.com';

    private string $schema = '';

    private ?Container $container = null;

    /** @var array<string, string> by the address the account signs in with */
    private array $passwords = [];

    protected function tearDown(): void
    {
        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->container = null;
        $this->passwords = [];

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testTheFirstRateOfABusinessIsItsDefaultAndTheNextOneIsNot(): void
    {
        $this->signIn(self::KEEPER);

        $first = $this->rates()->add('Standard rate', VatRate::ofPercent('21'), 'CZ', TaxRateKind::Standard);
        $second = $this->rates()->add('Reduced rate', VatRate::ofPercent('12'), 'CZ', TaxRateKind::Reduced);

        self::assertTrue($first->isDefault());
        self::assertFalse($second->isDefault());
        self::assertSame($first->id(), $this->rates()->default()?->id());
        self::assertSame(['Standard rate', 'Reduced rate'], $this->namesOf($this->rates()->offered()));
    }

    /** What is written is taken as written, the country in capitals; what cannot be one is refused. */
    public function testANameIsRequiredAndACountryIsItsTwoLetters(): void
    {
        $this->signIn(self::KEEPER);

        $added = $this->rates()->add('  Zero rate  ', VatRate::ofPercent('0'), ' cz ', TaxRateKind::Zero);
        self::assertSame('Zero rate', $added->name());
        self::assertSame('CZ', $added->country());

        $ownRate = $this->rates()->add('Our own rate', VatRate::ofPercent('4.9'), '', null);
        self::assertNull($ownRate->country());
        self::assertNull($ownRate->kind());

        $this->assertRefused(fn(): TaxRate => $this->rates()->add('   ', VatRate::ofPercent('21'), 'CZ', null));
        $this->assertRefused(fn(): TaxRate => $this->rates()->add('Standard rate', VatRate::ofPercent('21'), 'CZE', null));
        $this->assertRefused(fn(): TaxRate => $this->rates()->add('Standard rate', VatRate::ofPercent('21'), 'C1', null));
        $this->assertRefused(fn(): TaxRate => $this->rates()->add(str_repeat('x', TaxRate::MAX_NAME_LENGTH + 1), VatRate::ofPercent('21'), null, null));

        self::assertSame(['Zero rate', 'Our own rate'], $this->namesOf($this->rates()->offered()));
    }

    public function testTheDefaultCannotBeArchived(): void
    {
        $this->signIn(self::KEEPER);
        $standard = $this->rates()->add('Standard rate', VatRate::ofPercent('21'), 'CZ', TaxRateKind::Standard);

        $this->assertRefused(fn() => $this->rates()->archive($standard->id() ?? 0));

        self::assertNotNull($this->rates()->whyNotArchive($standard));
        self::assertFalse($this->reread($standard)->isArchived());
    }

    /** Making another rate the default lets go of the one before: there is one, and only one. */
    public function testMakingAnotherRateTheDefaultLeavesExactlyOne(): void
    {
        $this->signIn(self::KEEPER);
        $standard = $this->rates()->add('Standard rate', VatRate::ofPercent('21'), 'CZ', TaxRateKind::Standard);
        $reduced = $this->rates()->add('Reduced rate', VatRate::ofPercent('12'), 'CZ', TaxRateKind::Reduced);

        $this->rates()->makeDefault($reduced->id() ?? 0);

        self::assertSame([$reduced->id()], $this->defaultsOfThisBusiness());
        $this->rates()->archive($standard->id() ?? 0);
        self::assertTrue($this->reread($standard)->isArchived());
    }

    /**
     * That there is never a second default is the database's to hold, so a
     * row written past the service is refused as well.
     */
    public function testTheDatabaseRefusesASecondDefaultWrittenPastTheService(): void
    {
        $this->signIn(self::KEEPER);
        $this->rates()->add('Standard rate', VatRate::ofPercent('21'), 'CZ', TaxRateKind::Standard);
        $reduced = $this->rates()->add('Reduced rate', VatRate::ofPercent('12'), 'CZ', TaxRateKind::Reduced);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection()->executeStatement('UPDATE core_tax_rate SET is_default = 1 WHERE id = ?', [$reduced->id()]);
    }

    /**
     * That the default is never archived is the database's to hold as well,
     * so a row archived past the service is refused.
     */
    public function testTheDatabaseRefusesAnArchivedDefaultWrittenPastTheService(): void
    {
        $this->signIn(self::KEEPER);
        $standard = $this->rates()->add('Standard rate', VatRate::ofPercent('21'), 'CZ', TaxRateKind::Standard);

        $this->expectException(DriverException::class);
        $this->connection()->executeStatement('UPDATE core_tax_rate SET archived_at = NOW() WHERE id = ?', [$standard->id()]);
    }

    /**
     * Somebody makes a rate the default while somebody else, who read it
     * before, archives it: the archiving is refused rather than leaving an
     * archived default.
     */
    public function testARateMadeTheDefaultMeanwhileIsNotArchived(): void
    {
        $this->signIn(self::KEEPER);
        $standard = $this->rates()->add('Standard rate', VatRate::ofPercent('21'), 'CZ', TaxRateKind::Standard);
        $reduced = $this->rates()->add('Reduced rate', VatRate::ofPercent('12'), 'CZ', TaxRateKind::Reduced);

        // The other person's request, written past this one's entity manager,
        // which still holds the reduced rate as it read it.
        $this->connection()->executeStatement('UPDATE core_tax_rate SET is_default = 0 WHERE id = ?', [$standard->id()]);
        $this->connection()->executeStatement('UPDATE core_tax_rate SET is_default = 1 WHERE id = ?', [$reduced->id()]);

        $this->assertRefused(fn() => $this->rates()->archive($reduced->id() ?? 0));

        self::assertNull($this->connection()->fetchOne('SELECT archived_at FROM core_tax_rate WHERE id = ?', [$reduced->id()]));
        self::assertSame([$reduced->id()], $this->defaultsOfThisBusiness());
    }

    /** The other way round: a rate archived meanwhile is not made the default by somebody who read it before. */
    public function testARateArchivedMeanwhileIsNotMadeTheDefault(): void
    {
        $this->signIn(self::KEEPER);
        $standard = $this->rates()->add('Standard rate', VatRate::ofPercent('21'), 'CZ', TaxRateKind::Standard);
        $reduced = $this->rates()->add('Reduced rate', VatRate::ofPercent('12'), 'CZ', TaxRateKind::Reduced);

        $this->connection()->executeStatement('UPDATE core_tax_rate SET archived_at = NOW() WHERE id = ?', [$reduced->id()]);

        $this->assertRefused(fn() => $this->rates()->makeDefault($reduced->id() ?? 0));

        self::assertSame([$standard->id()], $this->defaultsOfThisBusiness());
    }

    /** Another business's default is its own: one default per business, not per installation. */
    public function testEachBusinessHasADefaultOfItsOwn(): void
    {
        $this->signIn(self::KEEPER);
        $this->rates()->add('Standard rate', VatRate::ofPercent('21'), 'CZ', TaxRateKind::Standard);

        self::assertCount(2, $this->connection()->fetchFirstColumn('SELECT id FROM core_tax_rate WHERE is_default = 1'));
    }

    /**
     * An archived rate is offered for nothing new, cannot be the default and
     * is kept as it was; restored, it is offered again.
     */
    public function testAnArchivedRateIsKeptAsItWasUntilItIsRestored(): void
    {
        $this->signIn(self::KEEPER);
        $this->rates()->add('Standard rate', VatRate::ofPercent('21'), 'CZ', TaxRateKind::Standard);
        $reduced = $this->rates()->add('Reduced rate', VatRate::ofPercent('12'), 'CZ', TaxRateKind::Reduced);
        $id = $reduced->id() ?? 0;

        $this->rates()->archive($id);

        self::assertSame(['Standard rate'], $this->namesOf($this->rates()->offered()));
        $this->assertRefused(fn(): TaxRate => $this->rates()->revise($id, 'Reduced rate', VatRate::ofPercent('10'), 'CZ', TaxRateKind::Reduced));
        $this->assertRefused(fn() => $this->rates()->makeDefault($id));
        $this->assertRefused(fn() => $this->rates()->archive($id));
        self::assertSame(1200, $this->reread($reduced)->rate()->basisPoints());

        $this->rates()->restore($id);

        self::assertSame(['Standard rate', 'Reduced rate'], $this->namesOf($this->rates()->offered()));
        $this->assertRefused(fn() => $this->rates()->restore($id));
    }

    /**
     * Changing the rate itself is written to the audit trail, because it
     * changes what everything priced at it costs with tax; changing only what
     * it is called is not.
     */
    public function testAChangeOfTheRateIsWrittenToTheAuditTrailAndNothingElseIs(): void
    {
        $this->signIn(self::KEEPER);
        $reduced = $this->rates()->add('Reduced rate', VatRate::ofPercent('15'), 'CZ', TaxRateKind::Reduced);
        $id = $reduced->id() ?? 0;
        self::assertSame([], $this->auditOf($id), 'adding a rate was written as a change of it');

        $this->rates()->revise($id, 'Reduced rate', VatRate::ofPercent('12'), 'CZ', TaxRateKind::Reduced);
        self::assertSame([TaxRates::RATE_CHANGED], $this->auditOf($id));
        self::assertSame(1200, $this->reread($reduced)->rate()->basisPoints());

        $this->rates()->revise($id, 'The reduced rate', VatRate::ofPercent('12'), 'CZ', TaxRateKind::Reduced);
        self::assertSame([TaxRates::RATE_CHANGED], $this->auditOf($id), 'a new name was written as a change of the rate');
        self::assertSame('The reduced rate', $this->reread($reduced)->name());
    }

    /**
     * The change of the rate and its entry in the audit trail are written
     * together or not at all: a trail that cannot be written leaves the rate
     * as it was, rather than changed with nothing saying so.
     */
    public function testAChangeOfTheRateWhoseAuditCannotBeWrittenIsNotKept(): void
    {
        $this->signIn(self::KEEPER);
        $reduced = $this->rates()->add('Reduced rate', VatRate::ofPercent('15'), 'CZ', TaxRateKind::Reduced);

        // The trail made unwritable in this class's own schema; it is dropped after the case.
        $this->connection()->executeStatement('RENAME TABLE core_audit_entry TO core_audit_entry_away');

        try {
            $this->rates()->revise($reduced->id() ?? 0, 'Reduced rate', VatRate::ofPercent('12'), 'CZ', TaxRateKind::Reduced);
            self::fail('the change went through without its audit entry');
        } catch (DriverException) {
            // The trail's table is not there; what matters is what was kept.
        }

        self::assertSame(1500, $this->connection()->fetchOne('SELECT rate FROM core_tax_rate WHERE id = ?', [$reduced->id()]));
    }

    /** Each act asks for its own privilege: seeing the rates is not changing them. */
    public function testWithoutTheRightToKeepTheRatesNothingIsChanged(): void
    {
        $this->signIn(self::KEEPER);
        $standard = $this->rates()->add('Standard rate', VatRate::ofPercent('21'), 'CZ', TaxRateKind::Standard);
        $reduced = $this->rates()->add('Reduced rate', VatRate::ofPercent('12'), 'CZ', TaxRateKind::Reduced);
        $this->rates()->archive($reduced->id() ?? 0);
        $offered = $this->rates()->add('Zero rate', VatRate::ofPercent('0'), 'CZ', TaxRateKind::Zero);

        foreach ([self::VIEWER, self::EDITOR] as $email) {
            $this->signIn($email);

            $this->assertRefused(fn(): TaxRate => $this->rates()->add('Another rate', VatRate::ofPercent('5'), null, null));
            $this->assertRefused(fn(): TaxRate => $this->rates()->revise($standard->id() ?? 0, 'Standard rate', VatRate::ofPercent('23'), 'CZ', null));
            $this->assertRefused(fn() => $this->rates()->makeDefault($offered->id() ?? 0));
            $this->assertRefused(fn() => $this->rates()->archive($offered->id() ?? 0));
            $this->assertRefused(fn() => $this->rates()->restore($reduced->id() ?? 0));
        }

        self::assertSame(2100, $this->reread($standard)->rate()->basisPoints());
        self::assertSame(['Standard rate', 'Zero rate'], $this->namesOf($this->rates()->offered()));
        self::assertSame([$standard->id()], $this->defaultsOfThisBusiness());
    }

    /** A rate of another business is not found here, so nothing can be done to it from here. */
    public function testARateOfAnotherBusinessCannotBeReachedFromThisOne(): void
    {
        $theirs = $this->rateOfBelemnite();
        $this->signIn('alice@example.com');
        $this->rates()->add('Standard rate', VatRate::ofPercent('21'), 'CZ', TaxRateKind::Standard);

        self::assertNull($this->rates()->find($theirs));
        $this->assertRefused(fn(): TaxRate => $this->rates()->revise($theirs, 'Taken over', VatRate::ofPercent('1'), null, null));
        $this->assertRefused(fn() => $this->rates()->makeDefault($theirs));
        $this->assertRefused(fn() => $this->rates()->archive($theirs));
        self::assertSame(['Standard rate'], $this->namesOf($this->rates()->offered()));

        $rate = $this->connection()->fetchAssociative('SELECT name, rate, is_default FROM core_tax_rate WHERE id = ?', [$theirs]);
        self::assertSame(['name' => 'Their standard rate', 'rate' => 2300, 'is_default' => 1], $rate);
    }

    /**
     * Rates taken from a template are the business's like any other, carrying
     * the template's label, country, kind and percent and the key they were
     * taken under; the first of a business with no rate yet is its default.
     */
    public function testRatesAdoptedFromATemplateAreTheBusinesssWithTheirKeys(): void
    {
        $this->signIn(self::KEEPER);

        $adopted = $this->rates()->adopt(['cz.standard', 'cz.zero']);

        self::assertSame(['Standard rate', 'Zero rate (books and the like)'], $this->namesOf($adopted));
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT r.name, r.rate, r.country, r.kind, r.template_key, r.is_default FROM core_tax_rate r JOIN core_tenant t ON t.id = r.tenant_id WHERE t.name = ? ORDER BY r.position',
            ['Ammonite Bikes'],
        );
        self::assertSame([
            ['name' => 'Standard rate', 'rate' => 2100, 'country' => 'CZ', 'kind' => 'standard', 'template_key' => 'cz.standard', 'is_default' => 1],
            ['name' => 'Zero rate (books and the like)', 'rate' => 0, 'country' => 'CZ', 'kind' => 'zero', 'template_key' => 'cz.zero', 'is_default' => 0],
        ], $rows);
    }

    /** A business that has a default keeps it: adopting is adding, and adding does not move the default. */
    public function testAdoptingDoesNotMoveTheDefault(): void
    {
        $this->signIn(self::KEEPER);
        $own = $this->rates()->add('Our own rate', VatRate::ofPercent('15'), null, null);

        $this->rates()->adopt(['sk.standard']);

        self::assertSame([$own->id()], $this->defaultsOfThisBusiness());
    }

    /**
     * A rate taken from a template once is not offered again, archived or
     * not, and taking it again - a form posted by hand - is refused with
     * nothing written; the same key twice in one request is one rate.
     */
    public function testARateAdoptedOnceIsNotOfferedOrAdoptedAgain(): void
    {
        $this->signIn(self::KEEPER);
        $templates = $this->container()->getByType(TaxRateTemplates::class);
        $czech = $templates->of('CZ') ?? self::fail('there is no Czech template');

        $this->rates()->adopt(['cz.standard', 'cz.reduced', 'cz.reduced']);
        self::assertSame(['cz.zero'], $this->keysOf($this->rates()->adoptable($czech)));

        $reduced = $this->connection()->fetchOne('SELECT id FROM core_tax_rate WHERE template_key = ?', ['cz.reduced']);
        self::assertIsNumeric($reduced);
        $this->rates()->archive((int) $reduced);
        self::assertSame(['cz.zero'], $this->keysOf($this->rates()->adoptable($czech)), 'an archived rate is offered again');

        $this->assertRefused(fn(): array => $this->rates()->adopt(['cz.zero', 'cz.reduced']));
        self::assertSame(['cz.reduced', 'cz.standard'], $this->templateKeysOfThisBusiness());
    }

    /** What is asked for is a rate of a template or nothing is taken at all. */
    public function testAKeyOfNoTemplateIsRefusedAndNothingIsAdopted(): void
    {
        $this->signIn(self::KEEPER);

        $this->assertRefused(fn(): array => $this->rates()->adopt(['cz.standard', 'cz.nothing']));
        $this->assertRefused(fn(): array => $this->rates()->adopt([]));

        self::assertSame([], $this->templateKeysOfThisBusiness());
    }

    /** Another business's rates taken from a template are its own: a key is unique within a business, not across them. */
    public function testTwoBusinessesAdoptTheSameRateEach(): void
    {
        $this->signIn(self::KEEPER);
        $this->connection()->executeStatement("UPDATE core_tax_rate SET template_key = 'cz.standard' WHERE name = ?", ['Their standard rate']);

        $this->rates()->adopt(['cz.standard']);

        self::assertSame(['cz.standard'], $this->templateKeysOfThisBusiness());
    }

    /**
     * That a business holds a rate of a template once is the database's to
     * hold, so the same key written twice past the service is refused.
     */
    public function testTheDatabaseRefusesARateOfOneTemplateKeyTwice(): void
    {
        $this->signIn(self::KEEPER);
        $this->rates()->adopt(['cz.standard']);
        $other = $this->rates()->add('Our own rate', VatRate::ofPercent('15'), null, null);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection()->executeStatement("UPDATE core_tax_rate SET template_key = 'cz.standard' WHERE id = ?", [$other->id()]);
    }

    /**
     * Somebody else takes the same rate between this request reading what is
     * offered and writing it: the database refuses the second, and the
     * refusal is a sentence, with nothing of this request kept.
     */
    public function testARateAdoptedMeanwhileBySomebodyElseIsRefusedInASentence(): void
    {
        $this->signIn(self::KEEPER);
        $tenant = $this->connection()->fetchOne('SELECT id FROM core_tenant WHERE name = ?', ['Ammonite Bikes']);
        $meanwhile = new class ($this->connection(), is_numeric($tenant) ? (int) $tenant : 0) {
            private bool $done = false;

            public function __construct(private readonly Connection $connection, private readonly int $tenant) {}

            public function onFlush(OnFlushEventArgs $flush): void
            {
                if ($this->done || $flush->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() === []) {
                    return;
                }

                $this->done = true;
                $this->connection->executeStatement(
                    "INSERT INTO core_tax_rate (tenant_id, name, rate, country, kind, position, template_key, is_default, created_at, updated_at) VALUES (?, 'Theirs', 2100, 'CZ', 'standard', 9, 'cz.standard', 0, NOW(), NOW())",
                    [$this->tenant],
                );
            }
        };
        $this->container()->getByType(EntityManagerInterface::class)->getEventManager()->addEventListener([Events::onFlush], $meanwhile);

        try {
            $this->rates()->adopt(['cz.zero', 'cz.standard']);
            self::fail('the same rate was taken twice');
        } catch (TaxRateConflict $conflict) {
            self::assertStringContainsString('at the same moment', $conflict->getMessage());
        }

        self::assertSame([], $this->templateKeysOfThisBusiness(), 'something of the refused request was kept');
    }

    /** Adopting is adding a rate, and asks the same right. */
    public function testWithoutTheRightToAddARateNothingIsAdopted(): void
    {
        foreach ([self::VIEWER, self::EDITOR] as $email) {
            $this->signIn($email);
            $this->assertRefused(fn(): array => $this->rates()->adopt(['cz.standard']));
        }

        self::assertSame([], $this->templateKeysOfThisBusiness());
    }

    /**
     * @param list<TemplateRate> $rates
     *
     * @return list<string>
     */
    private function keysOf(array $rates): array
    {
        return array_map(static fn(TemplateRate $rate): string => $rate->templateKey, $rates);
    }

    /** @return list<string> the template keys the first business holds, in their order, read past the mapping */
    private function templateKeysOfThisBusiness(): array
    {
        $keys = $this->connection()->fetchFirstColumn(
            'SELECT r.template_key FROM core_tax_rate r JOIN core_tenant t ON t.id = r.tenant_id WHERE t.name = ? AND r.template_key IS NOT NULL ORDER BY r.template_key',
            ['Ammonite Bikes'],
        );

        return array_map(static fn(mixed $key): string => is_string($key) ? $key : '', $keys);
    }

    /** @param callable(): mixed $act */
    private function assertRefused(callable $act): void
    {
        try {
            $act();
        } catch (TaxRateRefused $refused) {
            self::assertNotSame('', $refused->getMessage(), 'a refusal has to say why');

            return;
        }

        self::fail('it was done');
    }

    /**
     * @param list<TaxRate> $rates
     *
     * @return list<string>
     */
    private function namesOf(array $rates): array
    {
        return array_map(static fn(TaxRate $rate): string => $rate->name(), $rates);
    }

    /** $rate as the database holds it now, rather than as this process last saw it. */
    private function reread(TaxRate $rate): TaxRate
    {
        $entityManager = $this->container()->getByType(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(TaxRate::class, $rate->id()) ?? self::fail('the rate is gone');
    }

    /** @return list<int> the rates of the first business marked as its default, read past the mapping */
    private function defaultsOfThisBusiness(): array
    {
        $ids = $this->connection()->fetchFirstColumn(
            'SELECT r.id FROM core_tax_rate r JOIN core_tenant t ON t.id = r.tenant_id WHERE t.name = ? AND r.is_default = 1',
            ['Ammonite Bikes'],
        );

        return array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids);
    }

    /** @return list<string> what the audit trail holds about the rate $id, oldest first */
    private function auditOf(int $id): array
    {
        $actions = $this->connection()->fetchFirstColumn(
            'SELECT action FROM core_audit_entry WHERE entity_type = ? AND entity_id = ? ORDER BY id ASC',
            [TaxRate::class, (string) $id],
        );

        return array_map(static fn(mixed $action): string => is_string($action) ? $action : '', $actions);
    }

    private function rateOfBelemnite(): int
    {
        $id = $this->connection()->fetchOne('SELECT id FROM core_tax_rate WHERE name = ?', ['Their standard rate']);

        return is_numeric($id) ? (int) $id : self::fail('the other business has no rate');
    }

    private function signIn(string $email): void
    {
        $signedIn = $this->container()->getByType(SignedIn::class);
        $signedIn->logout(true);
        $signedIn->login($email, $this->passwords[$email] ?? self::fail('no account ' . $email));
    }

    private function rates(): TaxRates
    {
        return $this->container()->getByType(TaxRates::class);
    }

    private function accounts(): Accounts
    {
        return $this->container()->getByType(Accounts::class);
    }

    private function connection(): Connection
    {
        return $this->container()->getByType(Connection::class);
    }

    /**
     * A build of Core alone over a schema of this class's own, with two
     * businesses: the second has a rate of its own, which is its default,
     * and the first has none yet and the people each case signs in as.
     */
    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::coreAlone();
        $this->container = $container;
        Migrations::run($container);
        $entityManager = $container->getByType(EntityManagerInterface::class);

        $belemnite = Tenants::enter($container, 'Belemnite Books');
        $theirs = new TaxRate($belemnite, 'Their standard rate', VatRate::ofPercent('23'), 'SK', TaxRateKind::Standard, 1, new DateTimeImmutable());
        $theirs->makeDefault(new DateTimeImmutable());
        $entityManager->persist($theirs);
        $entityManager->flush();

        $made = Tenants::enter($container, 'Ammonite Bikes');
        // Moving into a second business empties the entity manager, so the
        // business is read back rather than held on to as it was made.
        $ammonite = $entityManager->find(Tenant::class, $made->id()) ?? self::fail('the first business cannot be read back');
        $owners = $this->accounts()->ownersRole($container->getByType(PermissionStructure::class));
        $entityManager->flush();

        $this->member($ammonite, 'alice@example.com', 'Alice Ammonite', $owners);
        $this->member($ammonite, self::KEEPER, 'Fay Foraminifera', $this->role($ammonite, 'finance', [
            new Grant(Resource::TaxRate, Privilege::View),
            new Grant(Resource::TaxRate, Privilege::Add),
            new Grant(Resource::TaxRate, Privilege::Edit),
            new Grant(Resource::TaxRate, Privilege::Delete),
        ]));
        $this->member($ammonite, self::VIEWER, 'Vera Volute', $this->role($ammonite, 'viewer', [
            new Grant(Resource::TaxRate, Privilege::View),
        ]));
        $this->member($ammonite, self::EDITOR, 'Eddie Echinoid', $this->role($ammonite, 'editor', [
            new Grant(Resource::Content, Privilege::View),
            new Grant(Resource::Content, Privilege::Edit),
        ]));

        return $container;
    }

    /** @param list<Grant> $grants */
    private function role(Tenant $business, string $code, array $grants): Role
    {
        $role = Role::ofBusiness($business, $code, ucfirst($code), array_map(
            static fn(Grant $grant): string => $grant->code(),
            $grants,
        ));
        $entityManager = $this->container()->getByType(EntityManagerInterface::class);
        $entityManager->persist($role);
        $entityManager->flush();

        return $role;
    }

    private function member(Tenant $business, string $email, string $name, Role $role): void
    {
        $container = $this->container ?? self::fail('no build yet');
        $password = Random::generate(20);
        $this->passwords[$email] = $password;

        $account = new User($email, $container->getByType(Passwords::class)->hash($password), $name, new DateTimeImmutable());
        $this->accounts()->save($account);

        $entityManager = $container->getByType(EntityManagerInterface::class);
        $entityManager->persist(new Membership($business, $account, $role));
        $entityManager->flush();
    }
}
