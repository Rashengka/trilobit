<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Cms;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Dom\Element;
use Dom\HTMLDocument;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\ForwardResponse;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Cms\Application\Page\Pages;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Presentation\Listing\Listing;
use Trilobit\Core\Security\Accounts;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * The list of pages in the administration, filtered and paged from its
 * address (.ai/plans/15, the first listing to use the mechanism).
 *
 * Every case here is a request as the browser makes it without a script: the
 * state is in the address, the filter is a form sent by GET, and the answer is
 * a page. What the script adds - redrawing the snippet, the history - is
 * measured in tests/e2e/listing.spec.ts.
 *
 * The rule the whole file keeps coming back to is that an address is only
 * ever read, never obeyed: what it asks for that the list does not have is set
 * aside with a sentence, a filter narrows what the business and the rights of
 * whoever is reading already allowed, and none of it is ever an error page.
 */
#[CoversNothing]
final class PageListTest extends TestCase
{
    private const string PRESENTER = 'Cms:Admin:Page';

    private const string LIST = 'cms-page-list';

    private string $schema = '';

    private ?Container $container = null;

    private ?Tenant $business = null;

    private ?string $fetchSite = null;

    /**
     * A signal is only obeyed from the site itself - otherwise Nette treats it
     * as forged and answers with the address the request came from - and a
     * browser sending the filter says it is, with Sec-Fetch-Site. So the
     * requests here say it too.
     */
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
        $this->business = null;

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testWithoutAFilterEveryPageIsListed(): void
    {
        $this->pagesTitled('First ride', 'Second ride', 'A walk');

        $document = $this->list([]);

        self::assertSame(['A walk', 'First ride', 'Second ride'], $this->titles($document));
        self::assertSame('3 pages.', $this->statusLine($document));
    }

    public function testAFilterInTheAddressNarrowsTheList(): void
    {
        $this->pagesTitled('First ride', 'Second ride', 'A walk');

        $document = $this->list(['pages-title' => 'ride']);

        self::assertSame(['First ride', 'Second ride'], $this->titles($document));
        self::assertSame('2 pages matching the filters.', $this->statusLine($document));
    }

    /** The form says what the list is filtered by, because the address is not where anybody reads it. */
    public function testTheFormShowsTheFilterTheAddressAskedFor(): void
    {
        $this->pagesTitled('First ride');
        $this->pages()->publish($this->pages()->all()[0]);

        $document = $this->list(['pages-title' => 'ride', 'pages-status' => 'published']);

        self::assertSame('ride', $this->field($document, 'title')->getAttribute('value'));
        self::assertSame(
            'published',
            $document->querySelector('select[name="status"] option[selected]')?->getAttribute('value'),
        );
    }

    /**
     * The filter is a form sent by GET, so without a script it is an address
     * like any other, and each field is named by a label of its own.
     */
    public function testTheFilterIsAFormSentByGetWithALabelForEveryField(): void
    {
        $this->pagesTitled('First ride');

        $document = $this->list(['pages-page' => '1']);

        $form = $document->querySelector('[role="search"] form');
        self::assertNotNull($form, 'the filter is not a form in a search landmark');
        self::assertSame('get', strtolower((string) $form->getAttribute('method')));
        self::assertTrue($form->classList->contains('ajax'), 'Naja is not asked to send the filter');

        foreach (['title', 'status'] as $name) {
            $id = (string) $this->field($document, $name)->getAttribute('id');
            self::assertNotSame('', $id);
            self::assertNotNull($document->querySelector(sprintf('label[for="%s"]', $id)), $name . ' has no label');
        }
    }

    public function testALongListIsPagedAndTheWayThroughItKeepsTheFilter(): void
    {
        $this->pagesTitled(...array_map(static fn(int $n): string => sprintf('Ride %02d', $n), range(1, Listing::PER_PAGE + 3)));
        $this->pagesTitled('A walk');

        $first = $this->list(['pages-title' => 'ride']);
        self::assertCount(Listing::PER_PAGE, $this->titles($first));
        self::assertSame('1', $this->currentPage($first));
        self::assertStringContainsString(', page 1 of 2', $this->statusLine($first));

        $next = $this->pageLink($first, 'Page 2');
        self::assertStringContainsString('pages-title=ride', $next);
        self::assertStringContainsString('pages-page=2', $next);

        $second = $this->list(['pages-title' => 'ride', 'pages-page' => '2']);
        self::assertSame(['Ride 21', 'Ride 22', 'Ride 23'], $this->titles($second));
        self::assertSame('2', $this->currentPage($second));
        self::assertStringNotContainsString('pages-page', $this->pageLink($second, 'Page 1'), 'the first page is named in the address');
    }

    /**
     * .ai/plans/15, the trap it names first: somebody on the second page
     * changes the filter. Kept on the second page, they would land on an
     * empty one and read it as the filter having found nothing.
     */
    public function testChangingTheFilterReturnsToTheFirstPage(): void
    {
        $response = $this->request([
            'pages-title' => 'ride',
            'pages-page' => '2',
            'do' => 'pages-filter-submit',
            'title' => 'walk',
            'status' => '',
        ]);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('pages-title=walk', $response->getUrl());
        self::assertStringNotContainsString('pages-page', $response->getUrl());
    }

    /** Emptying the form is taking the filter away, and the address says so by not carrying it. */
    public function testSendingAnEmptyFormTakesTheFilterAway(): void
    {
        $response = $this->request(['pages-title' => 'ride', 'do' => 'pages-filter-submit', 'title' => '', 'status' => '']);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringNotContainsString('pages-', $response->getUrl());
    }

    public function testTheFormIsSentWithoutTheStateItIsAboutToReplace(): void
    {
        $this->pagesTitled('First ride');

        $form = $this->list(['pages-title' => 'ride', 'pages-page' => '1'])->querySelector('[role="search"] form');
        self::assertNotNull($form);

        foreach ($form->querySelectorAll('input[type="hidden"]') as $hidden) {
            self::assertStringStartsNotWith('pages-', (string) $hidden->getAttribute('name'));
        }
    }

    /**
     * Nothing matching a filter and nothing written at all are two answers,
     * and only the first is offered the way back out of the filter.
     */
    public function testNothingMatchingIsNotNothingWritten(): void
    {
        $blank = $this->list([]);
        self::assertNotNull($blank->querySelector(sprintf('[data-testid="%s-empty"]', self::LIST)));
        self::assertNull($blank->querySelector(sprintf('[data-testid="%s-no-match"]', self::LIST)));
        self::assertSame('Nothing has been written yet.', $this->statusLine($blank));

        $this->pagesTitled('First ride');
        $filtered = $this->list(['pages-title' => 'nowhere']);
        self::assertNull($filtered->querySelector(sprintf('[data-testid="%s-empty"]', self::LIST)));
        self::assertNotNull($filtered->querySelector(sprintf('[data-testid="%s-no-match"]', self::LIST)));
        self::assertSame('No page matches the filters.', $this->statusLine($filtered));

        $clear = (string) $filtered->querySelector(sprintf('[data-testid="%s-clear"]', self::LIST))?->getAttribute('href');
        self::assertNotSame('', $clear);
        self::assertStringNotContainsString('pages-title', $clear);
    }

    public function testAFilterTheListDoesNotHaveIsSetAsideAndSaid(): void
    {
        $this->pagesTitled('First ride', 'A walk');

        $document = $this->list(['pages-colour' => 'red']);

        self::assertStringContainsString('colour', $this->setAside($document));
        self::assertSame(['A walk', 'First ride'], $this->titles($document));
        self::assertSame('2 pages.', $this->statusLine($document), 'a filter set aside was counted as a filter');
    }

    public function testAChoiceTheListDoesNotOfferIsSetAsideAndSaid(): void
    {
        $this->pagesTitled('First ride', 'A walk');

        $document = $this->list(['pages-status' => 'bogus', 'pages-title' => 'ride']);

        self::assertStringContainsString('bogus', $this->setAside($document));
        self::assertSame(['First ride'], $this->titles($document), 'the filter beside the one set aside was lost');
    }

    public function testAPagePastTheLastShowsTheLastAndSaysSo(): void
    {
        $this->pagesTitled(...array_map(static fn(int $n): string => sprintf('Ride %02d', $n), range(1, Listing::PER_PAGE + 1)));

        $document = $this->list(['pages-page' => '99']);

        self::assertStringContainsString('99', $this->setAside($document));
        self::assertSame('2', $this->currentPage($document));
        self::assertSame(['Ride 21'], $this->titles($document));
    }

    public function testAPageThatIsNoNumberShowsTheFirstAndSaysSo(): void
    {
        $this->pagesTitled('First ride');

        $document = $this->list(['pages-page' => 'abc']);

        self::assertStringContainsString('abc', $this->setAside($document));
        self::assertSame(['First ride'], $this->titles($document));
    }

    /**
     * The same address in two businesses lists each one's own pages - the
     * filter narrows what the tenant filter already narrowed, and the count
     * is of what is listed.
     */
    public function testTheListReadsOnlyTheBusinessItIsIn(): void
    {
        $container = $this->container();
        $this->pagesTitled('Ride in the hills');

        $other = Tenants::create($container, 'Belemnite Books');
        Tenants::switchTo($container, $other);
        $this->pagesTitled('Ride through the library', 'Ride to the reading room');
        Tenants::switchTo($container, $this->business());

        $document = $this->list(['pages-title' => 'ride']);

        self::assertSame(['Ride in the hills'], $this->titles($document));
        self::assertSame('1 page matching the filters.', $this->statusLine($document));
    }

    /**
     * An address does not open what its holder may not open. Somebody who
     * belongs to the business and may not view its content is refused the
     * list whatever the address filters it by, and refused the filter form
     * too - it is a signal of the same page.
     */
    public function testSomebodyWhoMayNotViewTheContentIsRefusedWhateverTheAddressSays(): void
    {
        $this->signedInAs(Role::ofBusiness($this->business(), 'onlooker', 'Onlooker', []));

        foreach ([['pages-title' => 'ride'], ['do' => 'pages-filter-submit', 'title' => 'ride', 'status' => '']] as $query) {
            $response = $this->request($query);

            self::assertInstanceOf(ForwardResponse::class, $response, 'refused nothing for ' . http_build_query($query));
            self::assertStringContainsString('Refusal', $response->getRequest()->getPresenterName());
        }
    }

    public function testSomebodyNotSignedInIsSentToSignInWhateverTheAddressSays(): void
    {
        $this->container()->getByType(SignedIn::class)->logout(true);

        $response = $this->request(['pages-title' => 'ride']);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('sign-in', $response->getUrl());
    }

    /**
     * @param array<string, string> $query
     */
    private function list(array $query): HTMLDocument
    {
        $response = $this->request($query);

        self::assertInstanceOf(TextResponse::class, $response, 'the list was not drawn for ' . http_build_query($query));
        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);

        return HTMLDocument::createFromString((string) $source, LIBXML_NOERROR);
    }

    /**
     * @param array<string, string> $query
     */
    private function request(array $query): Response
    {
        $presenter = $this->container()->getByType(IPresenterFactory::class)->createPresenter(self::PRESENTER);
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        return $presenter->run(new Request(self::PRESENTER, 'GET', ['action' => 'default', ...$query]));
    }

    /** @return list<string> */
    private function titles(HTMLDocument $document): array
    {
        $titles = [];
        foreach ($document->querySelectorAll(sprintf('[data-testid="%s-table"] tbody th', self::LIST)) as $cell) {
            $titles[] = trim((string) $cell->textContent);
        }

        return $titles;
    }

    private function statusLine(HTMLDocument $document): string
    {
        $status = $document->querySelector(sprintf('[data-testid="%s-status"]', self::LIST));
        self::assertNotNull($status, 'the list says nothing about how much it found');
        self::assertSame('status', $status->getAttribute('role'), 'what the list found is not said out loud');

        return trim((string) preg_replace('/\s+/', ' ', (string) $status->textContent));
    }

    private function setAside(HTMLDocument $document): string
    {
        $said = $document->querySelector(sprintf('[data-testid="%s-set-aside"]', self::LIST));
        self::assertNotNull($said, 'nothing was said about what the address asked for');

        return (string) $said->textContent;
    }

    private function currentPage(HTMLDocument $document): string
    {
        return trim((string) $document->querySelector(sprintf('[data-testid="%s-pagination"] [aria-current="page"]', self::LIST))?->textContent);
    }

    private function pageLink(HTMLDocument $document, string $label): string
    {
        $link = $document->querySelector(sprintf('[data-testid="%s-pagination"] a[aria-label="%s"]', self::LIST, $label));
        self::assertNotNull($link, 'there is no link to ' . $label);

        return (string) $link->getAttribute('href');
    }

    private function field(HTMLDocument $document, string $name): Element
    {
        $field = $document->querySelector(sprintf('[role="search"] [name="%s"]', $name));
        self::assertNotNull($field, 'the filter has no field ' . $name);

        return $field;
    }

    private function pagesTitled(string ...$titles): void
    {
        foreach ($titles as $title) {
            $this->pages()->create($title, 'page-' . strtolower(Random::generate(10)));
        }
    }

    private function pages(): Pages
    {
        return $this->container()->getByType(Pages::class);
    }

    private function business(): Tenant
    {
        $this->container();

        return $this->business ?? throw new \LogicException('The container enters a business as it is made.');
    }

    /**
     * A build with this module, a business to work inside, and its owner
     * signed in - held through a membership, the way `app:account --tenant`
     * makes one (see PageAdministrationTest::container()).
     */
    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::container(ModuleList::of(
            ['cms' => true, 'crm' => false, 'shop' => false],
            Bootstrap::rootDirectory(),
        ));
        Migrations::run($container);
        $this->business = Tenants::enter($container, 'Ammonite Bikes', Tenants::HOST);
        $this->container = $container;

        $this->signedInAs(new Role(Role::OWNER, 'Owner', ['app:*']));

        return $container;
    }

    private function signedInAs(Role $role): void
    {
        $container = $this->container();
        $container->getByType(SignedIn::class)->logout(true);

        $email = strtolower(Random::generate(8)) . '@example.com';
        $password = Random::generate(24, 'a-zA-Z0-9');
        $account = new User(
            $email,
            $container->getByType(Passwords::class)->hash($password),
            'Alice Ammonite',
            new DateTimeImmutable('2026-09-13T08:00:00+00:00'),
        );
        $container->getByType(Accounts::class)->save($account);

        $entityManager = $container->getByType(EntityManagerInterface::class);
        $entityManager->persist($role);
        $entityManager->persist(new Membership($this->business(), $account, $role));
        $entityManager->flush();

        $container->getByType(SignedIn::class)->login($email, $password);
    }
}
