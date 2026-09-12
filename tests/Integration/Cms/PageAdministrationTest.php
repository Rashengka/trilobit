<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Cms;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Dom\HTMLDocument;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\JsonResponse;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Cms\Application\Page\Pages;
use Trilobit\Cms\Domain\Page\Page;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Content\Categories;
use Trilobit\Core\Content\PathRegistry;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Security\Accounts;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * Writing a page from the administration, the way a person does it: through
 * the form, with the form's own answers coming back.
 *
 * The claim is not that the application service works - that is stated where
 * it is used - but that the two rows a page is made of are written and taken
 * away together. A page saved without its address, or an address left behind
 * by a deleted page, is the failure this suite exists for, and neither of them
 * shows up anywhere a person looks.
 *
 * The account and its password are made here and the password is generated, so
 * that nothing anybody could sign in with is in the repository. The request
 * carries the signal a submitted form carries and the environment carries the
 * Sec-Fetch-Site header a browser sends, which is what nette/forms 3.3 checks
 * in place of a token in the page - both are what a real browser posting this
 * form produces.
 */
#[CoversNothing]
final class PageAdministrationTest extends TestCase
{
    private const string PRESENTER = 'Cms:Admin:Page';

    private const string SUBMIT = 'page-submit';

    private string $schema = '';

    private ?Container $container = null;

    private string $generatedPassword = '';

    private ?string $fetchSite = null;

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

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testWritingAPageSavesItAndClaimsItsAddress(): void
    {
        $response = $this->submit('add', $this->values());

        self::assertInstanceOf(RedirectResponse::class, $response, 'the form did not accept the page');

        $page = $this->onlyPage();

        self::assertSame('About us', $page->title());
        self::assertFalse($page->isPublished(), 'a page starts as a draft');
        self::assertSame('about-us', $this->pages()->addressOf($page));
    }

    public function testPublishingFromTheFormPublishesThePage(): void
    {
        $this->submit('add', $this->values());
        $page = $this->onlyPage();

        $this->submit('edit', $this->values(['status' => 'published']), ['id' => (string) $page->id()]);

        self::assertTrue($this->onlyPage()->isPublished());
    }

    /** Moving a page from the form moves it in the register, which is the only place its address is. */
    public function testChangingTheAddressFromTheFormMovesItInTheRegister(): void
    {
        $this->submit('add', $this->values());
        $page = $this->onlyPage();

        $this->submit('edit', $this->values(['segment' => 'who-we-are']), ['id' => (string) $page->id()]);

        self::assertSame('who-we-are', $this->pages()->addressOf($this->onlyPage()));
    }

    /**
     * The register's refusal is shown on the form, in the words it was written
     * in, because whoever typed the address is the only person who can fix it.
     */
    public function testAnAddressTheRegisterRefusesIsShownOnTheForm(): void
    {
        $response = $this->submit('add', $this->values(['segment' => 'admin']));

        $error = $this->pageOf($response)->querySelector('[data-testid="cms-page-error"]');

        self::assertNotNull($error, 'the form said nothing about the address having been refused');
        self::assertStringContainsString("cannot start with 'admin'", (string) $error->textContent);
        self::assertSame([], $this->pages()->all(), 'a page with no address was left behind');
    }

    /** Deleting takes the page and gives its address back. */
    public function testDeletingAPageGivesItsAddressBack(): void
    {
        $this->submit('add', $this->values());
        $page = $this->onlyPage();

        $response = $this->submit('edit', ['delete' => 'Delete this page'], ['id' => (string) $page->id()]);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame([], $this->pages()->all());
        self::assertNull(
            $this->container()->getByType(PathRegistry::class)->find('about-us'),
            'the address of a deleted page is still claimed by it',
        );
    }

    /** The list is what an editor comes back to, so it has to say where each page answers. */
    public function testTheListShowsEveryPageAndWhereItAnswers(): void
    {
        $this->submit('add', $this->values());

        $document = $this->pageOf($this->submit('default', []));
        $id = $this->onlyPage()->id();

        self::assertSame(
            'About us',
            $document->querySelector(sprintf('[data-testid="cms-page-open-%d"]', $id))?->textContent,
        );
        self::assertSame(
            'Draft',
            $document->querySelector(sprintf('[data-testid="cms-page-status-%d"]', $id))?->textContent,
        );
        self::assertSame(
            '/about-us',
            $document->querySelector(sprintf('[data-testid="cms-page-address-%d"]', $id))?->textContent,
        );
    }

    /**
     * The list used to be a `<ul>` styled to look like a table, which is why it
     * never lined up. It has to actually be one: a `<table>` with column
     * headers, drawn by the shared c-table component (01d-design-system.md, D3)
     * rather than markup invented again for this one page.
     */
    public function testTheListIsARealTableWithColumnHeaders(): void
    {
        $this->submit('add', $this->values());

        $document = $this->pageOf($this->submit('default', []));

        $table = $document->querySelector('[data-testid="cms-page-list"] table');
        self::assertNotNull($table, 'the page list was expected to be drawn as a table, not a styled list');

        $headings = [];
        foreach ($document->querySelectorAll('[data-testid="cms-page-list"] thead th') as $heading) {
            $headings[] = trim((string) $heading->textContent);
        }

        self::assertSame(['Title', 'Status', 'Address', 'Actions'], $headings);

        $id = $this->onlyPage()->id();
        $row = $document->querySelector(sprintf('[data-testid="cms-page-%d"]', $id));
        self::assertNotNull($row, 'each page was expected to be a row, addressable by the same testid as before');
        self::assertSame('TR', $row->tagName);
    }

    /**
     * A link to see the page as a visitor would, from the list itself,
     * rather than making an editor open the form first to find the address.
     * It has to open in a new tab (so the editor keeps the list open) and it
     * has to have its own accessible name - an icon alone reads as "link" on
     * every row alike, which is no help in a list of twenty.
     */
    public function testTheListLinksToThePublicPageInANewTab(): void
    {
        $this->submit('add', $this->values());
        $id = $this->onlyPage()->id();

        $document = $this->pageOf($this->submit('default', []));

        $link = $document->querySelector(sprintf('[data-testid="cms-page-view-%d"]', $id));
        self::assertNotNull($link, 'the list was expected to link to the public page');

        self::assertSame('/about-us', $link->getAttribute('href'));
        self::assertSame('_blank', $link->getAttribute('target'));
        self::assertStringContainsString(
            'noopener',
            (string) $link->getAttribute('rel'),
            'target="_blank" without rel="noopener" lets the opened page reach back into this one',
        );

        $accessibleName = trim((string) $link->textContent);
        self::assertNotSame('', $accessibleName, 'an icon alone has no accessible name');
        self::assertStringContainsString(
            'About us',
            $accessibleName,
            'the accessible name has to name the page, or every row reads as the same link',
        );
    }

    /**
     * Decision C4: the category is chosen, and only the last part of the
     * address is written - the form puts the two together.
     */
    public function testAPageFiledInACategoryAnswersUnderIt(): void
    {
        $guides = $this->categories()->create('Guides', 'guides', null);

        $response = $this->submit('add', $this->values(['category' => $guides->ref->id, 'segment' => 'first-ride']));

        self::assertInstanceOf(RedirectResponse::class, $response, 'the form did not accept the page');
        self::assertSame('guides/first-ride', $this->pages()->addressOf($this->onlyPage()));
        self::assertSame('guides', $this->container()->getByType(PathRegistry::class)->find('guides/first-ride')?->parentPath);
    }

    /** Moving a page into a category from the form leaves its old address answering. */
    public function testFilingAPageIntoACategoryFromTheFormMovesItThere(): void
    {
        $this->submit('add', $this->values());
        $page = $this->onlyPage();
        $guides = $this->categories()->create('Guides', 'guides', null);

        $this->submit('edit', $this->values(['category' => $guides->ref->id]), ['id' => (string) $page->id()]);

        self::assertSame('guides/about-us', $this->pages()->addressOf($this->onlyPage()));
        self::assertSame('guides/about-us', $this->container()->getByType(PathRegistry::class)->find('about-us')?->movedTo);
    }

    public function testTheCategoriesAreOfferedToChooseFrom(): void
    {
        $this->categories()->create('Guides', 'guides', null);

        $document = $this->pageOf($this->submit('add', []));

        $offered = [];
        foreach ($document->querySelectorAll('select[name="category"] option') as $option) {
            $offered[] = trim((string) $option->textContent);
        }

        self::assertContains('Guides (/guides)', $offered);
    }

    /**
     * Decision C2: writing the whole address by hand is gone. A slash, a dot
     * and an extension are each refused with a sentence saying which, and no
     * page is left behind without an address.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function refusedLastParts(): iterable
    {
        yield 'a slash' => ['page-test/slash/test', 'more than one part'];
        yield 'an extension' => ['fun.html', 'a dot'];
        yield 'a dot' => ['fun.page', 'a dot'];
    }

    #[DataProvider('refusedLastParts')]
    public function testALastPartThatIsNotOneSegmentIsRefusedOnTheForm(string $segment, string $sentence): void
    {
        $response = $this->submit('add', $this->values(['segment' => $segment]));

        $error = $this->pageOf($response)->querySelector('[data-testid="cms-page-error"]');

        self::assertNotNull($error, 'the form said nothing about the address having been refused');
        self::assertStringContainsString($sentence, (string) $error->textContent);
        self::assertSame([], $this->pages()->all(), 'a page with no address was left behind');
    }

    /** The suggestion is the register's, so it knows what is taken in the category chosen. */
    public function testTheFormSuggestsTheLastPartFromTheTitle(): void
    {
        $guides = $this->categories()->create('Guides', 'guides', null);
        $this->pages()->create('First ride', 'first-ride', $guides->ref->id);

        self::assertSame(
            ['segment' => 'first-ride-2', 'message' => ''],
            $this->suggestion('add', ['title' => 'First ride', 'category' => $guides->ref->id]),
        );
        self::assertSame(
            ['segment' => 'first-ride', 'message' => ''],
            $this->suggestion('add', ['title' => 'First ride', 'category' => '']),
        );
    }

    public function testASuggestionForThePageBeingEditedLeavesItItsOwnAddress(): void
    {
        $this->submit('add', $this->values());
        $page = $this->onlyPage();

        self::assertSame(
            ['segment' => 'about-us', 'message' => ''],
            $this->suggestion('edit', ['title' => 'About us', 'category' => ''], ['id' => (string) $page->id()]),
        );
    }

    /** An empty title is answered with a sentence, rather than with an empty field and nothing said. */
    public function testASuggestionWithNothingToGoOnSaysSo(): void
    {
        $payload = $this->suggestion('add', ['title' => '', 'category' => '']);

        self::assertSame('', $payload['segment'] ?? null);
        self::assertNotSame('', $payload['message'] ?? '');
    }

    /**
     * An address typed out in full before categories existed has no category
     * and last part to show. Saving the form without touching either must not
     * move the page quietly; the form says what it is instead, and the page
     * moves only once somebody changes where it is filed.
     */
    public function testAnAddressTypedOutBeforeCategoriesStaysUntilSomebodyMovesIt(): void
    {
        $this->submit('add', $this->values());
        $page = $this->onlyPage();
        $ref = $page->ref();
        self::assertNotNull($ref);
        $registry = $this->container()->getByType(PathRegistry::class);
        $registry->forget('about-us');
        $registry->register($ref, 'typed/out/about-us', 'About us');
        $id = ['id' => (string) $page->id()];

        $notice = $this->pageOf($this->submit('edit', [], $id))->querySelector('[data-testid="cms-page-legacy-address"]');
        self::assertNotNull($notice, 'the form did not say the address was typed out by hand');
        self::assertStringContainsString('/typed/out/about-us', (string) $notice->textContent);

        $this->submit('edit', $this->values(['title' => 'About us, again']), $id);
        self::assertSame('typed/out/about-us', $this->pages()->addressOf($this->onlyPage()));

        $this->submit('edit', $this->values(['segment' => 'about']), $id);
        self::assertSame('about', $this->pages()->addressOf($this->onlyPage()));
        self::assertSame('about', $registry->find('typed/out/about-us')?->movedTo);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function values(array $overrides = []): array
    {
        return [
            'title' => 'About us',
            'category' => '',
            'segment' => 'about-us',
            'perex' => 'Who we are.',
            'content' => 'We make bicycles.',
            'seoTitle' => '',
            'seoDescription' => 'A small workshop.',
            'status' => 'draft',
            'send' => 'Save',
            ...$overrides,
        ];
    }

    /**
     * @param array<string, string> $post
     * @param array<string, string> $parameters
     */
    private function submit(string $action, array $post, array $parameters = []): Response
    {
        $container = $this->container();
        $presenter = $container->getByType(IPresenterFactory::class)->createPresenter(self::PRESENTER);
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        return $presenter->run(new Request(
            self::PRESENTER,
            $post === [] ? 'GET' : 'POST',
            ['action' => $action, ...($post === [] ? [] : ['do' => self::SUBMIT]), ...$parameters],
            $post,
        ));
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $parameters
     *
     * @return array<mixed>
     */
    private function suggestion(string $action, array $query, array $parameters = []): array
    {
        $presenter = $this->container()->getByType(IPresenterFactory::class)->createPresenter(self::PRESENTER);
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        $response = $presenter->run(new Request(
            self::PRESENTER,
            'GET',
            ['action' => $action, 'do' => 'suggestSegment', ...$query, ...$parameters],
        ));

        self::assertInstanceOf(JsonResponse::class, $response);
        $payload = $response->getPayload();
        self::assertIsArray($payload);

        return $payload;
    }

    private function categories(): Categories
    {
        return $this->container()->getByType(Categories::class);
    }

    private function pageOf(Response $response): HTMLDocument
    {
        self::assertInstanceOf(TextResponse::class, $response);
        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);

        return HTMLDocument::createFromString((string) $source, LIBXML_NOERROR);
    }

    private function onlyPage(): Page
    {
        $pages = $this->pages()->all();

        self::assertCount(1, $pages, 'the administration was expected to have written exactly one page');

        return $pages[0];
    }

    private function pages(): Pages
    {
        return $this->container()->getByType(Pages::class);
    }

    /**
     * A build with this module, a tenant to work inside, and somebody signed
     * in to do the work.
     *
     * **What that somebody holds, they hold in the business.** The role used
     * to be granted on the account row, which names no business - so it was a
     * right in all of them at once and, now that the pages of the
     * administration are gated, an answer in none. It is made here the way
     * `app:account --tenant` makes it: the owner's role, holding the whole of
     * the application, held through a membership.
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
        $tenant = Tenants::enter($container, 'Ammonite Bikes', Tenants::HOST);

        $this->generatedPassword = Random::generate(24, 'a-zA-Z0-9');
        $account = new User(
            'alice@example.com',
            $container->getByType(Passwords::class)->hash($this->generatedPassword),
            'Alice Ammonite',
            new DateTimeImmutable('2026-09-06T08:00:00+00:00'),
        );
        $container->getByType(Accounts::class)->save($account);

        $entityManager = $container->getByType(EntityManagerInterface::class);
        $role = new Role(Role::OWNER, 'Owner', ['app:*']);
        $entityManager->persist($role);
        $entityManager->persist(new Membership($tenant, $account, $role));
        $entityManager->flush();

        $container->getByType(SignedIn::class)->login('alice@example.com', $this->generatedPassword);

        return $this->container = $container;
    }
}
