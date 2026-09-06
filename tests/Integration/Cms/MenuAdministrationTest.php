<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Cms;

use DateTimeImmutable;
use Dom\HTMLDocument;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Response;
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
use Trilobit\Cms\Domain\Menu\MenuItem;
use Trilobit\Cms\Domain\Menu\MenuRepository;
use Trilobit\Cms\Domain\Menu\MenuTarget;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Security\Accounts;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * Arranging a menu from the administration, and the one thing this page has to
 * do that the site must not: show an entry that leads nowhere.
 *
 * The site leaves such an entry out, because a visitor following it would meet
 * an error - that is
 * Trilobit\Tests\Integration\Cms\MenuLeadingIntoASwitchedOffModuleTest. Here
 * the opposite is asserted, and the pair is the whole point: an entry hidden
 * in both places would be one nobody can find and nobody can remove.
 *
 * The build has no Shop in it, so the entry naming one is not a contrivance -
 * it is the ordinary state of an installation somebody switched a module off
 * in.
 */
#[CoversNothing]
final class MenuAdministrationTest extends TestCase
{
    private const string PRESENTER = 'Cms:Admin:Menu';

    private const string SUBMIT = 'entry-submit';

    /** A page of a module this build does not have, as a saved row names it. */
    private const string IN_ANOTHER_MODULE = 'Shop:Front:Status:default';

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

    public function testArrangingAnEntryIntoAMenuSavesIt(): void
    {
        $response = $this->submit('add', $this->values());

        self::assertInstanceOf(RedirectResponse::class, $response, 'the form did not accept the entry');

        $entry = $this->onlyEntry();

        self::assertSame(MenuItem::MAIN, $entry->menu());
        self::assertSame('Shop', $entry->label());
        self::assertSame(MenuTarget::Route, $entry->targetType());
        self::assertSame(self::IN_ANOTHER_MODULE, $entry->target());
        self::assertTrue($entry->isVisible());
    }

    /**
     * The administration says an entry leads nowhere rather than hiding it,
     * because whoever arranged it is the only person who can decide whether
     * the module should come back or the entry should go.
     */
    public function testAnEntryThisBuildCannotDrawIsShownWithAWordAboutWhy(): void
    {
        $this->submit('add', $this->values());
        $id = $this->onlyEntry()->id();

        $document = $this->pageOf($this->submit('default', []));

        self::assertNotNull(
            $document->querySelector(sprintf('[data-testid="cms-menu-open-%d"]', $id)),
            'the entry is missing from the administration as well as from the site',
        );
        self::assertNotNull(
            $document->querySelector(sprintf('[data-testid="cms-menu-unreachable-%d"]', $id)),
            'nothing said the entry leads nowhere in this build',
        );
    }

    public function testAnEntryLeadingToAPageOfThisSiteHoldsThePage(): void
    {
        $page = $this->container()->getByType(Pages::class)->create('About us', 'about-us');

        $this->submit('add', $this->values([
            'label' => 'About us',
            'targetType' => 'page',
            'page' => (string) $page->id(),
            'target' => '',
        ]));

        $entry = $this->onlyEntry();

        self::assertSame(MenuTarget::Page, $entry->targetType());
        self::assertSame($page->id(), $entry->page()?->id());
    }

    /** A kind and a value that do not go together is a shape the domain cannot hold, so the form refuses it. */
    public function testAnEntryLeadingToAPageWithNoPageChosenIsRefused(): void
    {
        $response = $this->submit('add', $this->values(['targetType' => 'page', 'target' => '']));

        $error = $this->pageOf($response)->querySelector('[data-testid="cms-menu-error"]');

        self::assertNotNull($error, 'the form said nothing about the entry leading nowhere');
        self::assertSame([], $this->entries()->all());
    }

    public function testAnEntryLeadingToNothingAtAllIsRefused(): void
    {
        $response = $this->submit('add', $this->values(['target' => '']));

        self::assertNotNull($this->pageOf($response)->querySelector('[data-testid="cms-menu-error"]'));
        self::assertSame([], $this->entries()->all());
    }

    public function testDeletingAnEntryRemovesIt(): void
    {
        $this->submit('add', $this->values());
        $id = $this->onlyEntry()->id();

        $response = $this->submit('edit', ['delete' => 'Delete this entry'], ['id' => (string) $id]);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame([], $this->entries()->all());
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function values(array $overrides = []): array
    {
        return [
            'menu' => MenuItem::MAIN,
            'label' => 'Shop',
            'targetType' => 'route',
            'page' => '',
            'target' => self::IN_ANOTHER_MODULE,
            'parent' => '',
            'position' => '10',
            'visible' => '1',
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
        $presenter = $this->container()->getByType(IPresenterFactory::class)->createPresenter(self::PRESENTER);
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        return $presenter->run(new Request(
            self::PRESENTER,
            $post === [] ? 'GET' : 'POST',
            ['action' => $action, ...($post === [] ? [] : ['do' => self::SUBMIT]), ...$parameters],
            $post,
        ));
    }

    private function pageOf(Response $response): HTMLDocument
    {
        self::assertInstanceOf(TextResponse::class, $response);
        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);

        return HTMLDocument::createFromString((string) $source, LIBXML_NOERROR);
    }

    private function onlyEntry(): MenuItem
    {
        $entries = $this->entries()->all();

        self::assertCount(1, $entries, 'the administration was expected to have arranged exactly one entry');

        return $entries[0];
    }

    private function entries(): MenuRepository
    {
        return $this->container()->getByType(MenuRepository::class);
    }

    /** A build without the module the entry names, a tenant, and somebody signed in. */
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
        Tenants::enter($container, 'Ammonite Bikes', Tenants::HOST);

        $this->generatedPassword = Random::generate(24, 'a-zA-Z0-9');
        $account = new User(
            'alice@example.com',
            $container->getByType(Passwords::class)->hash($this->generatedPassword),
            'Alice Ammonite',
            new DateTimeImmutable('2026-09-06T08:00:00+00:00'),
        );
        $account->grant(new Role('administrator', 'Administrator', ['administration']));
        $container->getByType(Accounts::class)->save($account);

        $container->getByType(SignedIn::class)->login('alice@example.com', $this->generatedPassword);

        return $this->container = $container;
    }
}
