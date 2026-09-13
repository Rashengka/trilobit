<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Cms;

use Dom\Element;
use Dom\HTMLDocument;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request as ApplicationRequest;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Http\IRequest;
use Nette\Routing\Router;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Cms\Application\Page\Pages;
use Trilobit\Cms\Domain\Menu\MenuItem;
use Trilobit\Cms\Domain\Menu\MenuRepository;
use Trilobit\Cms\Navigation\ArrangedEntries;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Domain\Navigation\Menu;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Navigation\Composition;
use Trilobit\Core\Navigation\HomeEntry;
use Trilobit\Core\Navigation\Menus;
use Trilobit\Core\Navigation\ModuleSections;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Double\StandInHttpRequest;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * The site's own navigation: what the build contributes, what the business
 * arranged in its menu, and the order the business saved for the two
 * (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3, decided 2026-09-13).
 *
 * The navigation belongs to the application - Core draws it - and this module
 * is one of the things contributing to it: the entries somebody arranged at
 * /admin/cms/menus. So what is asked here is the seam between the two, drawn
 * the way a visitor meets it: a page answered through the router, and the
 * navigation of its layout read back.
 *
 * The page no longer draws the same menu a second time inside its own content
 * (decided 2026-09-13, O5): the one in the layout is the menu.
 */
#[CoversNothing]
final class MainNavigationTest extends TestCase
{
    private const string HOST = 'http://localhost';

    private string $schema = '';

    private ?Container $container = null;

    protected function tearDown(): void
    {
        $this->container = null;

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testWithNothingArrangedTheNavigationIsHomeTheMenuAndThenTheSections(): void
    {
        self::assertSame(['Home', 'About us', 'Customer care', 'Cms'], $this->topLevelOf($this->page('/')));
    }

    public function testAnEntryWithEntriesUnderItBringsThemIntoTheNavigation(): void
    {
        $page = $this->page('/');

        $care = $this->entry($page, 'nav-menu-customer-care');
        self::assertSame('/customer-care', $care->getAttribute('href'));

        $under = [];
        foreach ($care->parentElement?->querySelectorAll('.c-nav__sub .c-nav__link') ?? [] as $link) {
            $under[] = trim((string) $link->textContent);
        }

        self::assertSame(['Delivery', 'Returns'], $under);
    }

    public function testWhatAVisitorCouldNotFollowIsLeftOut(): void
    {
        $page = $this->page('/');

        self::assertNull($page->querySelector('[data-testid="nav-menu-summer-sale"]'), 'an entry leading to a draft was drawn');
        self::assertNull($page->querySelector('[data-testid="nav-menu-staff-room"]'), 'an entry somebody hid was drawn');
    }

    public function testThePageBeingReadAndTheEntriesItIsUnderAreMarked(): void
    {
        $page = $this->page('/delivery');

        self::assertSame('page', $this->entry($page, 'nav-menu-delivery')->getAttribute('aria-current'));
        self::assertSame('true', $this->entry($page, 'nav-menu-customer-care')->getAttribute('aria-current'));
        self::assertNull($this->entry($page, 'nav-menu-about-us')->getAttribute('aria-current'));
        self::assertNull($this->entry($page, 'nav-home')->getAttribute('aria-current'));
    }

    public function testAPageDrawsNoSecondMenuInsideItsContent(): void
    {
        $page = $this->page('/about-us');

        self::assertSame('About us', $page->querySelector('[data-testid="cms-page-title"]')?->textContent);
        self::assertNull($page->querySelector('[data-testid="cms-menu"]'), 'the page still draws the menu inside its content');
        self::assertNull($page->querySelector('[data-testid="layout-content"] nav'), 'the content holds a navigation of its own');
        self::assertSame('page', $this->entry($page, 'nav-menu-about-us')->getAttribute('aria-current'));
    }

    /**
     * What the business saved decides the order of the contributors and which
     * of them are drawn at all; what each one holds, and in which order, stays
     * that contributor's.
     */
    public function testTheArrangementTheBusinessSavedDecidesOrderAndWhatIsDrawn(): void
    {
        $menus = $this->container()->getByType(Menus::class);
        $main = $menus->namedOrNew(Menu::MAIN);

        $main->recompose(new Composition([ModuleSections::KEY, HomeEntry::KEY, ArrangedEntries::KEY]));
        $menus->save($main);
        self::assertSame(['Cms', 'Home', 'About us', 'Customer care'], $this->topLevelOf($this->page('/')));

        $main->recompose(new Composition([], [ArrangedEntries::KEY]));
        $menus->save($main);
        self::assertSame(['Home', 'Cms'], $this->topLevelOf($this->page('/')));
    }

    /** @return list<string> the labels of the navigation's own entries, in the order they are drawn */
    private function topLevelOf(HTMLDocument $page): array
    {
        $labels = [];
        foreach ($page->querySelectorAll('[data-testid="layout-nav"] .c-nav__list > .c-nav__item > .c-nav__link') as $link) {
            $labels[] = trim((string) $link->textContent);
        }

        return $labels;
    }

    private function entry(HTMLDocument $page, string $testId): Element
    {
        $entry = $page->querySelector(sprintf('[data-testid="layout-nav"] [data-testid="%s"]', $testId));
        self::assertInstanceOf(Element::class, $entry, sprintf('the navigation has no entry %s', $testId));

        return $entry;
    }

    private function page(string $path): HTMLDocument
    {
        $container = $this->container();

        $httpRequest = $container->getByType(IRequest::class);
        self::assertInstanceOf(StandInHttpRequest::class, $httpRequest);
        $httpRequest->arriveAt(self::HOST . $path);

        $params = $container->getByType(Router::class)->match($httpRequest);
        self::assertNotNull($params, sprintf('nothing is routed at %s', $path));

        $name = $params['presenter'] ?? null;
        self::assertIsString($name);

        $presenter = $container->getByType(IPresenterFactory::class)->createPresenter($name);
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        $response = $presenter->run(new ApplicationRequest($name, 'GET', $params));
        self::assertInstanceOf(TextResponse::class, $response, sprintf('%s answered with something other than a page', $path));

        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);

        return HTMLDocument::createFromString((string) $source, LIBXML_NOERROR);
    }

    /**
     * One business with a menu holding every case: an entry of its own, one
     * with two under it, one leading to a draft and one somebody hid.
     */
    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::container(
            ModuleList::of(['cms' => true, 'crm' => false, 'shop' => false], Bootstrap::rootDirectory()),
            config: ['services' => ['http.request' => ['factory' => StandInHttpRequest::class]]],
        );
        Migrations::run($container);
        Tenants::enter($container, 'Ammonite Bikes', Tenants::HOST);

        $pages = $container->getByType(Pages::class);
        $published = static function (string $title, string $segment) use ($pages) {
            $page = $pages->create($title, $segment);
            $pages->publish($page);

            return $page;
        };

        $about = $published('About us', 'about-us');
        $care = $published('Customer care', 'customer-care');
        $delivery = $published('Delivery', 'delivery');
        $returns = $published('Returns', 'returns');
        $draft = $pages->create('Summer sale', 'summer-sale');

        $main = $container->getByType(Menus::class)->namedOrNew(Menu::MAIN);
        $entries = $container->getByType(MenuRepository::class);

        $entries->save(MenuItem::toPage($main, 'About us', $about, 10));

        $careEntry = MenuItem::toPage($main, 'Customer care', $care, 20);
        $entries->save($careEntry);
        foreach ([$delivery, $returns] as $position => $page) {
            $entry = MenuItem::toPage($main, $page->title(), $page, $position);
            $entry->fileUnder($careEntry);
            $entries->save($entry);
        }

        $entries->save(MenuItem::toPage($main, 'Summer sale', $draft, 30));

        $staff = MenuItem::toUrl($main, 'Staff room', 'https://www.example.org/staff', 40);
        $staff->hide();
        $entries->save($staff);

        return $this->container = $container;
    }
}
