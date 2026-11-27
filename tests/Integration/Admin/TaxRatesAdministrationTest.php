<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Admin;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Dom\HTMLDocument;
use Nette\Application\BadRequestException;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\ForwardResponse;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Http\Request as HttpRequest;
use Nette\Http\UrlScript;
use Nette\Routing\Router;
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
 * A business's rates of tax, from the pages they are kept on: the section's
 * signpost, the list, and the page a rate is added or changed on.
 *
 * What Trilobit\Tests\Integration\Finance\TaxRatesTest asks of the service is
 * asked here of the pages, and one question more: who is shown the section at
 * all. Somebody who holds nothing of it is refused its pages and is not
 * offered a way into them on the bar; somebody who may only see the rates is
 * shown them and no way to change them.
 */
#[CoversNothing]
final class TaxRatesAdministrationTest extends TestCase
{
    private const string RATES = 'Core:Finance:TaxRates';

    private const string SIGNPOST = 'Core:Finance:Signpost';

    private const string REFUSAL = 'Core:Error:Refusal';

    private const string KEEPER = 'fay@example.com';

    private const string VIEWER = 'vera@example.com';

    private const string EDITOR = 'eddie@example.com';

    /** May see the rates and archive or restore them, and nothing more. */
    private const string ARCHIVER = 'archie@example.com';

    private string $schema = '';

    private ?Container $container = null;

    private ?string $fetchSite = null;

    /** @var array<string, string> by the address the account signs in with */
    private array $passwords = [];

    /** @var array<string, int> the rates every case starts with, by name */
    private array $rates = [];

    protected function setUp(): void
    {
        $this->fetchSite = isset($_SERVER['HTTP_SEC_FETCH_SITE']) && is_string($_SERVER['HTTP_SEC_FETCH_SITE'])
            ? $_SERVER['HTTP_SEC_FETCH_SITE']
            : null;
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
    }

    protected function tearDown(): void
    {
        if ($this->fetchSite === null) {
            unset($_SERVER['HTTP_SEC_FETCH_SITE']);
        } else {
            $_SERVER['HTTP_SEC_FETCH_SITE'] = $this->fetchSite;
        }

        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->container = null;
        $this->passwords = [];
        $this->rates = [];

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testTheirAddressesAreTheFinancePagesOfTheAdministration(): void
    {
        self::assertSame([self::SIGNPOST, 'default'], $this->routed('/admin/finance'));
        self::assertSame([self::RATES, 'default'], $this->routed('/admin/finance/tax-rates'));
        self::assertSame([self::RATES, 'add'], $this->routed('/admin/finance/tax-rates/add'));
        self::assertSame([self::RATES, 'edit'], $this->routed('/admin/finance/tax-rates/7'));
    }

    public function testTheRatesOfThisBusinessAreListedAndNoOtherBusinesss(): void
    {
        $this->signIn(self::KEEPER);

        $list = $this->contentOf($this->request(self::RATES, 'default'));

        self::assertStringContainsString('Standard rate', $list);
        self::assertStringContainsString('Reduced rate', $list);
        self::assertStringContainsString("21\u{a0}%", $list);
        self::assertStringNotContainsString('Their standard rate', $list, 'a rate of another business is listed here');

        $page = $this->pageOf($this->request(self::RATES, 'default'));
        self::assertSame('Default', $this->textOf($page, 'tax-rate-state-' . $this->rates['Standard rate']));
        self::assertSame('Offered', $this->textOf($page, 'tax-rate-state-' . $this->rates['Reduced rate']));
    }

    public function testTheListIsFilteredFromTheAddress(): void
    {
        $this->signIn(self::KEEPER);

        $list = $this->contentOf($this->request(self::RATES, 'default', ['rates-kind' => 'reduced']));

        self::assertStringContainsString('Reduced rate', $list);
        self::assertStringNotContainsString('Standard rate', $list);
    }

    /** The section is the keeper's, and its signpost leads to the rates. */
    public function testTheSignpostOfTheSectionLeadsToTheRates(): void
    {
        $this->signIn(self::KEEPER);

        $page = $this->pageOf($this->request(self::SIGNPOST, 'default'));

        $tiles = $page->querySelectorAll('[data-testid="finance-signpost"] a');
        self::assertCount(1, $tiles);
        self::assertSame('/admin/finance/tax-rates', $tiles->item(0)?->getAttribute('href'));
    }

    /**
     * Somebody holding nothing of the money matters is refused both pages,
     * and the bar they are drawn offers no way to either.
     */
    public function testSomebodyWhoMayNotSeeTheRatesIsRefusedThemAndNotOfferedThem(): void
    {
        $this->signIn(self::EDITOR);

        $this->assertRefusedByTheGate($this->request(self::RATES, 'default'));
        $this->assertRefusedByTheGate($this->request(self::SIGNPOST, 'default'));
        self::assertNotContains('/admin/finance/tax-rates', $this->menuOf($this->request('Core:Admin:Dashboard', 'default')));

        $this->signIn(self::KEEPER);
        self::assertContains('/admin/finance/tax-rates', $this->menuOf($this->request(self::RATES, 'default')));
    }

    /**
     * Seeing the rates is not changing them: a rate's page opens, and draws
     * nothing that changes it - and a form posted to it anyway is refused by
     * the service, not by whether a button was drawn.
     */
    public function testSomebodyWhoMayOnlySeeTheRatesIsShownNoWayToChangeThem(): void
    {
        $this->signIn(self::VIEWER);
        $reduced = (string) $this->rates['Reduced rate'];

        $list = $this->pageOf($this->request(self::RATES, 'default'));
        self::assertNull($list->querySelector('[data-testid="tax-rates-add"]'), 'the list offers a way to add a rate');
        self::assertSame('Standard rate', $this->textOf($list, 'tax-rate-open-' . $this->rates['Standard rate']));
        $this->assertRefusedByTheGate($this->request(self::RATES, 'add'));

        $page = $this->pageOf($this->request(self::RATES, 'edit', ['id' => $reduced]));
        foreach (['tax-rate-form', 'tax-rate-default', 'tax-rate-archive', 'tax-rate-restore'] as $form) {
            self::assertNull($page->querySelector('[data-testid="' . $form . '"]'), 'the page draws ' . $form);
        }

        self::assertSame('You may see this rate and not change it.', $this->textOf($page, 'tax-rate-kept'));
        self::assertNull($page->querySelector('[data-testid="tax-rate-state-panel"]'), 'a panel with nothing in it is drawn');

        $posted = [
            'rate-submit' => ['name' => 'Taken over', 'rate' => '1', 'country' => '', 'kind' => '', 'save' => 'Save'],
            'default-submit' => ['choose' => 'Make it the default'],
            'archive-submit' => ['archive' => 'Archive'],
        ];
        foreach ($posted as $signal => $post) {
            $response = $this->request(self::RATES, 'edit', ['id' => $reduced, 'do' => $signal], $post);
            self::assertInstanceOf(RedirectResponse::class, $response, $signal . ' was not refused with a sentence');
        }

        $row = $this->connection()->fetchAssociative('SELECT name, rate, is_default, archived_at FROM core_tax_rate WHERE id = ?', [$reduced]);
        self::assertSame(['name' => 'Reduced rate', 'rate' => 1200, 'is_default' => 0, 'archived_at' => null], $row);
    }

    /**
     * The default, seen by somebody who may only see the rates: it is said to
     * be the default, as a state - not with advice they cannot follow - and
     * that is all its panel of acts holds.
     */
    public function testTheDefaultIsSaidToBeTheDefaultWithoutAdviceToSomebodyWhoMayDoNothing(): void
    {
        $this->signIn(self::VIEWER);

        $page = $this->pageOf($this->request(self::RATES, 'edit', ['id' => (string) $this->rates['Standard rate']]));

        self::assertSame(
            'This is the default: a new price starts with it, and the default cannot be archived.',
            $this->textOf($page, 'tax-rate-default-note'),
        );
        self::assertCount(1, $page->querySelectorAll('[data-testid="tax-rate-state-panel"] p, [data-testid="tax-rate-state-panel"] form'));
        self::assertSame('21' . "\u{a0}" . '% - Standard rate', $this->textOf($page, 'tax-rate-headline'));
    }

    /**
     * An archived rate is shown by its facts to everybody, a missing one said
     * as missing; restoring it is suggested only to somebody who may.
     */
    public function testAnArchivedRateShowsItsFactsAndSuggestsRestoringOnlyToWhoeverMay(): void
    {
        $connection = $this->connection();
        $reduced = (string) $this->rates['Reduced rate'];
        $connection->executeStatement(
            'UPDATE core_tax_rate SET archived_at = NOW(), kind = NULL WHERE id = ?',
            [$reduced],
        );
        // Written past the entity manager, which still holds the rate as it was made.
        $this->container()->getByType(EntityManagerInterface::class)->clear();

        foreach ([self::VIEWER => 'An archived rate is kept as it was.', self::ARCHIVER => 'An archived rate is kept as it was. Restore it to change it.'] as $email => $kept) {
            $this->signIn($email);
            $page = $this->pageOf($this->request(self::RATES, 'edit', ['id' => $reduced]));

            self::assertSame($kept, $this->textOf($page, 'tax-rate-kept'), $email);
            $facts = [];
            foreach ($page->querySelectorAll('[data-testid="tax-rate-facts"] dd') as $fact) {
                $facts[] = trim($fact->textContent ?? '');
            }

            self::assertSame(['CZ', 'No kind'], $facts, $email);
            self::assertStringNotContainsString('Restore', $this->textOf($page, 'tax-rate-lead'), $email);
        }
    }

    /**
     * Each act on a rate's page asks for its own right: somebody who may see
     * the rates and archive them, and nothing more, archives and restores one
     * from its page and is drawn no way to change it or make it the default.
     */
    public function testSomebodyWhoMayArchiveButNotChangeArchivesAndRestoresARate(): void
    {
        $this->signIn(self::ARCHIVER);
        $reduced = (string) $this->rates['Reduced rate'];

        $page = $this->pageOf($this->request(self::RATES, 'edit', ['id' => $reduced]));
        self::assertNull($page->querySelector('[data-testid="tax-rate-form"]'), 'the page offers a way to change the rate');
        self::assertNull($page->querySelector('[data-testid="tax-rate-default"]'), 'the page offers a way to make it the default');
        self::assertNotNull($page->querySelector('[data-testid="tax-rate-archive"]'), 'the page offers no way to archive it');

        $this->request(self::RATES, 'edit', ['id' => $reduced, 'do' => 'archive-submit'], ['archive' => 'Archive']);
        self::assertSame([$this->rates['Reduced rate']], $this->idsWhere('archived_at IS NOT NULL'));

        $page = $this->pageOf($this->request(self::RATES, 'edit', ['id' => $reduced]));
        self::assertNotNull($page->querySelector('[data-testid="tax-rate-restore"]'), 'the page offers no way to restore it');

        $this->request(self::RATES, 'edit', ['id' => $reduced, 'do' => 'restore-submit'], ['restore' => 'Restore']);
        self::assertSame([], $this->idsWhere('archived_at IS NOT NULL'));
    }

    /**
     * A rate archived by somebody else after this request read it is not made
     * the default: the database refuses, and the page answers with a redirect
     * rather than drawing itself from an entity manager the refusal closed.
     */
    public function testARateArchivedMeanwhileIsNotMadeTheDefaultFromItsPage(): void
    {
        $this->signIn(self::KEEPER);
        $reduced = (string) $this->rates['Reduced rate'];
        // The rate read as it is now, and held so by the entity manager.
        $this->pageOf($this->request(self::RATES, 'edit', ['id' => $reduced]));

        $this->connection()->executeStatement('UPDATE core_tax_rate SET archived_at = NOW() WHERE id = ?', [$reduced]);
        $response = $this->request(self::RATES, 'edit', ['id' => $reduced, 'do' => 'default-submit'], ['choose' => 'Make it the default']);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame([$this->rates['Standard rate']], $this->idsWhere('is_default = 1'));
    }

    /**
     * A rate added the moment somebody else's first rate is written is
     * refused by the database; the form answers with a redirect and the
     * reason, rather than drawing itself from the entity manager the refusal
     * closed.
     */
    public function testARateRefusedByAWriteMeanwhileIsAnsweredWithARedirect(): void
    {
        $this->signIn(self::KEEPER);
        $standard = $this->rates['Standard rate'];
        $connection = $this->connection();

        // The business seen with no default, and given one by somebody else
        // between that read and the write of the new rate, which, seen as the
        // first, is written as the default too. The other write goes over this
        // test's one connection, inside the transaction of the new rate, so it
        // goes back with it; what is asked is that the new rate is not kept
        // and the page is not drawn.
        $connection->executeStatement('UPDATE core_tax_rate SET is_default = 0 WHERE id = ?', [$standard]);
        $meanwhile = new class ($connection, $standard) {
            private bool $done = false;

            public function __construct(private readonly Connection $connection, private readonly int $standard) {}

            public function onFlush(OnFlushEventArgs $flush): void
            {
                $inserted = $flush->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions();
                if ($this->done || array_filter($inserted, static fn(object $entity): bool => $entity instanceof TaxRate) === []) {
                    return;
                }

                $this->done = true;
                $this->connection->executeStatement('UPDATE core_tax_rate SET is_default = 1 WHERE id = ?', [$this->standard]);
            }
        };
        $this->container()->getByType(EntityManagerInterface::class)->getEventManager()->addEventListener([Events::onFlush], $meanwhile);

        $response = $this->request(self::RATES, 'add', ['do' => 'rate-submit'], [
            'name' => 'Zero rate',
            'rate' => '0',
            'country' => 'CZ',
            'kind' => 'zero',
            'save' => 'Save',
        ]);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertFalse($connection->fetchOne('SELECT id FROM core_tax_rate WHERE name = ?', ['Zero rate']));
    }

    public function testARateIsAddedFromTheForm(): void
    {
        $this->signIn(self::KEEPER);

        $response = $this->request(self::RATES, 'add', ['do' => 'rate-submit'], [
            'name' => 'Zero rate',
            'rate' => '0',
            'country' => 'cz',
            'kind' => 'zero',
            'save' => 'Save',
        ]);

        self::assertInstanceOf(RedirectResponse::class, $response);
        $row = $this->connection()->fetchAssociative('SELECT rate, country, kind, is_default FROM core_tax_rate WHERE name = ?', ['Zero rate']);
        self::assertSame(['rate' => 0, 'country' => 'CZ', 'kind' => 'zero', 'is_default' => 0], $row);
    }

    /** A rate the form cannot read is said beside the field, and nothing is written. */
    public function testARateThatIsNotOneIsRefusedOnTheForm(): void
    {
        $this->signIn(self::KEEPER);

        $response = $this->request(self::RATES, 'add', ['do' => 'rate-submit'], [
            'name' => 'Odd rate',
            'rate' => 'twelve',
            'country' => '',
            'kind' => '',
            'save' => 'Save',
        ]);

        self::assertInstanceOf(TextResponse::class, $response);
        self::assertStringContainsString("'twelve' is not a rate of tax", $this->contentOf($response));
        self::assertFalse($this->connection()->fetchOne('SELECT id FROM core_tax_rate WHERE name = ?', ['Odd rate']));
    }

    public function testChangingARateFromItsPageSavesIt(): void
    {
        $this->signIn(self::KEEPER);
        $reduced = (string) $this->rates['Reduced rate'];

        $page = $this->pageOf($this->request(self::RATES, 'edit', ['id' => $reduced]));
        self::assertSame('12', $page->querySelector('[data-testid="tax-rate-rate"]')?->getAttribute('value'));

        $this->request(self::RATES, 'edit', ['id' => $reduced, 'do' => 'rate-submit'], [
            'name' => 'Reduced rate',
            'rate' => '10',
            'country' => 'CZ',
            'kind' => 'reduced',
            'save' => 'Save',
        ]);

        self::assertSame(1000, $this->connection()->fetchOne('SELECT rate FROM core_tax_rate WHERE id = ?', [$reduced]));
    }

    /** The default is offered no way to be archived, and posting one anyway is refused in a sentence. */
    public function testTheDefaultIsNotArchivedEvenWhenTheFormIsPostedAnyway(): void
    {
        $this->signIn(self::KEEPER);
        $standard = (string) $this->rates['Standard rate'];

        $page = $this->pageOf($this->request(self::RATES, 'edit', ['id' => $standard]));
        self::assertNull($page->querySelector('[data-testid="tax-rate-archive"]'));
        self::assertStringContainsString('Make another rate the default first', $this->textOf($page, 'tax-rate-default-note'));

        $response = $this->request(self::RATES, 'edit', ['id' => $standard, 'do' => 'archive-submit'], ['archive' => 'Archive']);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertNull($this->connection()->fetchOne('SELECT archived_at FROM core_tax_rate WHERE id = ?', [$standard]));
    }

    /** Making another rate the default and archiving the one that was, each a submit on the rate's own page. */
    public function testTheDefaultMovesAndTheRateItLeftCanBeArchived(): void
    {
        $this->signIn(self::KEEPER);
        $standard = (string) $this->rates['Standard rate'];
        $reduced = (string) $this->rates['Reduced rate'];

        $this->request(self::RATES, 'edit', ['id' => $reduced, 'do' => 'default-submit'], ['choose' => 'Make it the default']);
        $this->request(self::RATES, 'edit', ['id' => $standard, 'do' => 'archive-submit'], ['archive' => 'Archive']);

        self::assertSame([$this->rates['Reduced rate']], $this->idsWhere('is_default = 1'));
        self::assertSame([$this->rates['Standard rate']], $this->idsWhere('archived_at IS NOT NULL'));
    }

    public function testTheTemplatesHaveAddressesOfTheirOwn(): void
    {
        self::assertSame([self::RATES, 'templates'], $this->routed('/admin/finance/tax-rates/templates'));
        self::assertSame([self::RATES, 'templates'], $this->routed('/admin/finance/tax-rates/templates/cz'));
        self::assertSame('/admin/finance/tax-rates/templates/at', $this->linkTo('templates', ['country' => 'at']));
    }

    /**
     * Adding from a template is adding a rate: the way to it is drawn only for
     * whoever may add one, and somebody who may only see the rates is refused
     * its page - and the form posted to it - by the gate.
     */
    public function testTheTemplatesAreOfferedOnlyToWhoeverMayAddARate(): void
    {
        $this->signIn(self::KEEPER);
        $list = $this->pageOf($this->request(self::RATES, 'default'));
        self::assertSame('/admin/finance/tax-rates/templates', $list->querySelector('[data-testid="tax-rates-from-template"]')?->getAttribute('href'));

        foreach ([self::VIEWER, self::ARCHIVER] as $email) {
            $this->signIn($email);
            $list = $this->pageOf($this->request(self::RATES, 'default'));
            self::assertNull($list->querySelector('[data-testid="tax-rates-from-template"]'), $email . ' is offered the templates');
            $this->assertRefusedByTheGate($this->request(self::RATES, 'templates'));
            $this->assertRefusedByTheGate($this->request(self::RATES, 'templates', ['country' => 'cz', 'do' => 'adopt-submit'], ['rates' => ['cz.zero'], 'adopt' => 'Add the chosen rates']));
        }

        self::assertSame([], $this->templateKeysHere());
    }

    /** The page of the templates leads to each country's, and says when each was checked. */
    public function testTheTemplatesPageLeadsToEveryCountry(): void
    {
        $this->signIn(self::KEEPER);

        $page = $this->pageOf($this->request(self::RATES, 'templates'));

        $addresses = [];
        foreach ($page->querySelectorAll('[data-testid="tax-rate-templates"] a') as $link) {
            $addresses[] = (string) $link->getAttribute('href');
        }

        self::assertSame(
            array_map(static fn(string $country): string => '/admin/finance/tax-rates/templates/' . $country, ['at', 'cz', 'de', 'hu', 'pl', 'sk']),
            $addresses,
        );
        self::assertNull($page->querySelector('[data-testid="tax-rate-template-form"]'), 'a form is drawn before a country is chosen');
    }

    /**
     * A country's rates are offered with where they come from and when they
     * were checked; the chosen ones are added to the business's rates with
     * the key of the template, and are offered no more.
     */
    public function testRatesOfACountryAreAdoptedFromItsTemplateAndOfferedNoMore(): void
    {
        $this->signIn(self::KEEPER);

        $page = $this->pageOf($this->request(self::RATES, 'templates', ['country' => 'cz']));
        self::assertSame(['cz.standard', 'cz.reduced', 'cz.zero'], $this->offeredOn($page));
        self::assertStringContainsString('27 November 2026', $this->textOf($page, 'tax-rate-template-verified'));
        $sources = [];
        foreach ($page->querySelectorAll('[data-testid="tax-rate-template-sources"] a') as $source) {
            $sources[] = (string) $source->getAttribute('href');
        }

        self::assertSame(['https://www.zakonyprolidi.cz/cs/2004-235', 'https://www.zakonyprolidi.cz/cs/2026-180'], $sources);

        $response = $this->request(self::RATES, 'templates', ['country' => 'cz', 'do' => 'adopt-submit'], [
            'rates' => ['cz.zero'],
            'adopt' => 'Add the chosen rates',
        ]);

        self::assertInstanceOf(RedirectResponse::class, $response);
        $row = $this->connection()->fetchAssociative('SELECT name, rate, country, kind, is_default FROM core_tax_rate WHERE template_key = ?', ['cz.zero']);
        self::assertSame(['name' => 'Zero rate (books and the like)', 'rate' => 0, 'country' => 'CZ', 'kind' => 'zero', 'is_default' => 0], $row);

        $page = $this->pageOf($this->request(self::RATES, 'templates', ['country' => 'cz']));
        self::assertSame(['cz.standard', 'cz.reduced'], $this->offeredOn($page));
        self::assertStringContainsString('Zero rate (books and the like)', $this->textOf($page, 'tax-rate-template-adopted'));
    }

    /**
     * A rate that is not offered - taken already, or of no template - posted
     * by hand is not taken, and neither is nothing: the form says why.
     */
    public function testOnlyARateOfferedIsTakenFromTheForm(): void
    {
        $this->signIn(self::KEEPER);
        $this->request(self::RATES, 'templates', ['country' => 'cz', 'do' => 'adopt-submit'], ['rates' => ['cz.zero'], 'adopt' => 'Add the chosen rates']);

        foreach ([['cz.zero'], ['cz.nothing'], ['sk.standard'], []] as $posted) {
            $response = $this->request(self::RATES, 'templates', ['country' => 'cz', 'do' => 'adopt-submit'], ['rates' => $posted, 'adopt' => 'Add the chosen rates']);
            self::assertInstanceOf(TextResponse::class, $response, implode(', ', $posted) . ' was taken');
            self::assertStringContainsString('Choose at least one rate', $this->textOf($this->pageOf($response), 'tax-rate-template-form'));
        }

        self::assertSame(['cz.zero'], $this->templateKeysHere());
    }

    /** Every rate of a template taken, the page says so and draws no form. */
    public function testATemplateWhoseEveryRateIsTakenOffersNothing(): void
    {
        $this->signIn(self::KEEPER);
        $this->request(self::RATES, 'templates', ['country' => 'de', 'do' => 'adopt-submit'], [
            'rates' => ['de.standard', 'de.reduced', 'de.zero'],
            'adopt' => 'Add the chosen rates',
        ]);

        $page = $this->pageOf($this->request(self::RATES, 'templates', ['country' => 'de']));

        self::assertNull($page->querySelector('[data-testid="tax-rate-template-form"]'));
        self::assertStringContainsString('Every rate of this template is among the rates of this business', $this->textOf($page, 'tax-rate-template-nothing'));
    }

    public function testACountryWithNoTemplateIsNotFound(): void
    {
        $this->signIn(self::KEEPER);

        $this->assertNotFound(fn(): Response => $this->request(self::RATES, 'templates', ['country' => 'xx']), 'templates');
    }

    /** A form of a rate's page is not there on another page, where another gate would stand in front of it. */
    public function testTheFormsOfARateAreTakenOnlyOnItsPage(): void
    {
        $this->signIn(self::KEEPER);

        foreach (['default', 'add', 'invented'] as $action) {
            $this->assertNotFound(fn(): Response => $this->request(self::RATES, $action, [
                'id' => (string) $this->rates['Reduced rate'],
                'do' => 'archive-submit',
            ], ['archive' => 'Archive']), $action);
        }

        self::assertSame([], $this->idsWhere('archived_at IS NOT NULL'));
    }

    public function testARateOfAnotherBusinessIsNotFoundHere(): void
    {
        $this->signIn(self::KEEPER);
        $theirs = $this->connection()->fetchOne('SELECT id FROM core_tax_rate WHERE name = ?', ['Their standard rate']);
        self::assertIsNumeric($theirs);

        $this->assertNotFound(fn(): Response => $this->request(self::RATES, 'edit', ['id' => (string) $theirs]), 'edit');
    }

    /** @param callable(): Response $request */
    private function assertNotFound(callable $request, string $action): void
    {
        try {
            $request();
        } catch (BadRequestException $notFound) {
            self::assertSame(404, $notFound->getHttpCode(), $action);

            return;
        }

        self::fail('it answered on ' . $action);
    }

    private function assertRefusedByTheGate(Response $response): void
    {
        self::assertInstanceOf(ForwardResponse::class, $response, 'the gate let the request through');
        self::assertSame(self::REFUSAL, $response->getRequest()->getPresenterName());
    }

    /** @return list<int> the rates of the first business for which $condition holds */
    private function idsWhere(string $condition): array
    {
        $ids = $this->connection()->fetchFirstColumn(
            'SELECT r.id FROM core_tax_rate r JOIN core_tenant t ON t.id = r.tenant_id WHERE t.name = ? AND ' . $condition . ' ORDER BY r.id',
            ['Ammonite Bikes'],
        );

        return array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids);
    }

    /** @return list<string> the template keys of the rates $page offers to take, in their order */
    private function offeredOn(HTMLDocument $page): array
    {
        $keys = [];
        foreach ($page->querySelectorAll('[data-testid="tax-rate-template-form"] input[type="checkbox"]') as $box) {
            $keys[] = (string) $box->getAttribute('value');
        }

        return $keys;
    }

    /** @return list<string> the template keys the first business holds, in their order, read past the mapping */
    private function templateKeysHere(): array
    {
        $keys = $this->connection()->fetchFirstColumn(
            'SELECT r.template_key FROM core_tax_rate r JOIN core_tenant t ON t.id = r.tenant_id WHERE t.name = ? AND r.template_key IS NOT NULL ORDER BY r.template_key',
            ['Ammonite Bikes'],
        );

        return array_map(static fn(mixed $key): string => is_string($key) ? $key : '', $keys);
    }

    /**
     * The address the router writes for an action of the rates' pages.
     *
     * @param array<string, string> $parameters
     */
    private function linkTo(string $action, array $parameters): string
    {
        $url = $this->container()->getByType(Router::class)->constructUrl(
            ['presenter' => self::RATES, 'action' => $action, ...$parameters],
            new UrlScript('http://localhost/', '/'),
        );
        self::assertIsString($url, 'the router writes no address for ' . $action);

        return (string) parse_url($url, PHP_URL_PATH);
    }

    /** @return list<string> the addresses the administration bar of $response leads to */
    private function menuOf(Response $response): array
    {
        $addresses = [];
        foreach ($this->pageOf($response)->querySelectorAll('[data-testid="admin-menu"] a') as $link) {
            $addresses[] = (string) $link->getAttribute('href');
        }

        return $addresses;
    }

    private function textOf(HTMLDocument $page, string $testId): string
    {
        $element = $page->querySelector('[data-testid="' . $testId . '"]');
        self::assertNotNull($element, 'nothing on the page is ' . $testId);

        return trim($element->textContent ?? '');
    }

    /**
     * @param array<string, string> $parameters
     * @param array<string, string|list<string>> $post
     */
    private function request(string $presenter, string $action, array $parameters = [], array $post = []): Response
    {
        $request = new Request($presenter, $post === [] ? 'GET' : 'POST', ['action' => $action, ...$parameters], $post);
        $made = $this->container()->getByType(IPresenterFactory::class)->createPresenter($request->getPresenterName());
        self::assertInstanceOf(Presenter::class, $made);
        $made->autoCanonicalize = false;

        return $made->run($request);
    }

    /** @return array{string, string} */
    private function routed(string $address): array
    {
        $matched = $this->container()->getByType(Router::class)->match(new HttpRequest(new UrlScript('http://localhost' . $address, '/')));
        self::assertIsArray($matched, $address . ' is not claimed by the router');
        self::assertIsString($matched['presenter'] ?? null);
        self::assertIsString($matched['action'] ?? null);

        return [$matched['presenter'], $matched['action']];
    }

    private function pageOf(Response $response): HTMLDocument
    {
        self::assertInstanceOf(TextResponse::class, $response);
        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);

        return HTMLDocument::createFromString((string) $source, LIBXML_NOERROR);
    }

    private function contentOf(Response $response): string
    {
        $content = $this->pageOf($response)->querySelector('[data-testid="admin-content"]');
        self::assertNotNull($content, 'the page drew no content');

        return trim((string) preg_replace('/\s+/', ' ', $content->textContent ?? ''));
    }

    private function signIn(string $email): void
    {
        $signedIn = $this->container()->getByType(SignedIn::class);
        $signedIn->logout(true);
        $signedIn->login($email, $this->passwords[$email] ?? self::fail('no account ' . $email));
    }

    private function connection(): Connection
    {
        return $this->container()->getByType(Connection::class);
    }

    /**
     * A build of Core alone over a schema of this class's own: a second
     * business with a rate of its own, and the first with a standard rate - its
     * default - a reduced one, and the people each case signs in as.
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
        $now = new DateTimeImmutable();

        $belemnite = Tenants::enter($container, 'Belemnite Books', 'belemnite.localhost');
        $theirs = new TaxRate($belemnite, 'Their standard rate', VatRate::ofPercent('23'), 'SK', TaxRateKind::Standard, 1, $now);
        $theirs->makeDefault($now);
        $entityManager->persist($theirs);
        $entityManager->flush();

        $made = Tenants::enter($container, 'Ammonite Bikes', 'localhost');
        // Moving into a second business empties the entity manager, so the
        // business is read back rather than held on to as it was made.
        $ammonite = $entityManager->find(Tenant::class, $made->id()) ?? self::fail('the business cannot be read back');

        $standard = new TaxRate($ammonite, 'Standard rate', VatRate::ofPercent('21'), 'CZ', TaxRateKind::Standard, 1, $now);
        $standard->makeDefault($now);
        $reduced = new TaxRate($ammonite, 'Reduced rate', VatRate::ofPercent('12'), 'CZ', TaxRateKind::Reduced, 2, $now);
        $entityManager->persist($standard);
        $entityManager->persist($reduced);
        $entityManager->flush();
        $this->rates = ['Standard rate' => $standard->id() ?? 0, 'Reduced rate' => $reduced->id() ?? 0];

        $owners = $container->getByType(Accounts::class)->ownersRole($container->getByType(PermissionStructure::class));
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
        $this->member($ammonite, self::ARCHIVER, 'Archie Ammonoid', $this->role($ammonite, 'archiver', [
            new Grant(Resource::TaxRate, Privilege::View),
            new Grant(Resource::TaxRate, Privilege::Delete),
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
        $container->getByType(Accounts::class)->save($account);

        $entityManager = $container->getByType(EntityManagerInterface::class);
        $entityManager->persist(new Membership($business, $account, $role));
        $entityManager->flush();
    }
}
