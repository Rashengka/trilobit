<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Cms;

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
use Trilobit\Cms\Domain\Page\Page;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Double\StandInHttpRequest;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * A menu entry that leads into a module this build does not have is left out
 * of the menu, and nothing about the page is otherwise different.
 *
 * This is the claim the whole idea of a switchable module rests on, seen from
 * the one place a person meets it. A menu entry is a row somebody saved, so it
 * outlives the module it names being switched off - exactly as a row in the
 * register of public addresses does. What must not happen is what happens by
 * default: asking the framework for a link into a presenter that is not in the
 * build does not stop the page, it draws a broken href and carries on, and the
 * menu then looks like a menu that works.
 *
 * So the test is written as a pair, and the pair is the point. The same rows
 * are put into two builds that differ in one line of configuration, and the
 * entry has to be absent from one and present in the other. Asserting only its
 * absence would pass just as well on a build where the whole menu had
 * disappeared, or where the entry had never been saved.
 *
 * The module that is switched off is named as a string here and nowhere in
 * src/Cms: a destination is text in a row, never a class, which is what makes
 * it possible to hold on to it while its module is away.
 */
#[CoversNothing]
final class MenuLeadingIntoASwitchedOffModuleTest extends TestCase
{
    private const string HOST = 'http://localhost';

    /** A page of a module this installation can be built without, as a saved row names it. */
    private const string IN_ANOTHER_MODULE = 'Shop:Front:Status:default';

    /** A page of the part that cannot be switched off, so that something in the menu is always drawable. */
    private const string IN_CORE = 'Core:Front:Home:default';

    /** @var list<string> */
    private array $schemas = [];

    protected function tearDown(): void
    {
        foreach ($this->schemas as $schema) {
            Database::drop($schema);
        }

        $this->schemas = [];
    }

    public function testAnEntryLeadingIntoAModuleThatIsNotInThisBuildIsLeftOut(): void
    {
        $menu = $this->menuOf($this->site(withTheOtherModule: false));

        self::assertNull(
            $menu->querySelector('[data-testid="cms-menu-shop"]'),
            'the entry into a module this build does not have was drawn',
        );
        self::assertNull(
            $menu->querySelector('a[href=""]'),
            'a dead anchor is worse than no entry',
        );
        self::assertStringNotContainsString(
            'error',
            (string) $menu->querySelector('[data-testid="cms-menu"]')?->textContent,
            'the framework wrote its own complaint into the page instead of the entry being left out',
        );
    }

    /**
     * The other half: the same rows in a build that has the module. Without
     * this, the assertion above would hold on a build that drew no menu at
     * all, or where the entry had never been saved in the first place.
     */
    public function testTheSameEntryIsDrawnWhereTheModuleIsInTheBuild(): void
    {
        $menu = $this->menuOf($this->site(withTheOtherModule: true));

        $entry = $menu->querySelector('[data-testid="cms-menu-shop"]');

        self::assertNotNull($entry, 'the entry was left out of a build that has the module');
        self::assertSame('/shop', $entry->getAttribute('href'));
    }

    /** And the rest of the menu is untouched by the absence, in either build. */
    public function testTheEntriesThisBuildCanDrawAreStillThere(): void
    {
        foreach ([false, true] as $withTheOtherModule) {
            $menu = $this->menuOf($this->site($withTheOtherModule));

            self::assertNotNull(
                $menu->querySelector('[data-testid="cms-menu-home"]'),
                'an entry into Core went missing as well',
            );
            self::assertNotNull(
                $menu->querySelector('[data-testid="cms-menu-about-us"]'),
                'an entry into this module went missing as well',
            );
        }
    }

    /**
     * The same rule applied to this module's own pages: an entry leading to a
     * page nobody may see is a link to a 404, so it is left out too - and the
     * page itself still answers.
     */
    public function testAnEntryLeadingToAPageThatIsNotPublishedIsLeftOut(): void
    {
        $menu = $this->menuOf($this->site(withTheOtherModule: true));

        self::assertNull(
            $menu->querySelector('[data-testid="cms-menu-not-ready"]'),
            'an entry leading to a draft was drawn',
        );
    }

    /** The page itself is drawn whole, which is what "does not throw" has to mean. */
    private function menuOf(Container $container): HTMLDocument
    {
        $document = $this->page($container, '/about-us');

        self::assertNotNull($document->querySelector('[data-testid="cms-menu"]'), 'no menu was drawn at all');
        self::assertSame('About us', $document->querySelector('[data-testid="cms-page-title"]')?->textContent);

        return $document;
    }

    private function page(Container $container, string $path): HTMLDocument
    {
        $httpRequest = $container->getByType(IRequest::class);
        self::assertInstanceOf(StandInHttpRequest::class, $httpRequest);
        $httpRequest->arriveAt(self::HOST . $path);

        $params = $container->getByType(Router::class)->match($httpRequest);
        self::assertNotNull($params, sprintf('nothing is routed at %s', $path));

        $name = $params['presenter'] ?? null;
        self::assertIsString($name);

        $presenter = $container->getByType(IPresenterFactory::class)->createPresenter($name);
        self::assertInstanceOf(Presenter::class, $presenter);

        $response = $presenter->run(new ApplicationRequest($name, 'GET', $params));
        self::assertInstanceOf(TextResponse::class, $response, 'the address answered with something other than a page');

        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);

        return HTMLDocument::createFromString((string) $source, LIBXML_NOERROR);
    }

    /**
     * One site, arranged the same way whichever build it is put into: a page
     * to stand on, a menu with one entry of each kind that can fail, and one
     * that cannot.
     *
     * The rows are written whether or not this build has the module the third
     * entry names, because that is the situation a customer's database is
     * really in - switching a module off takes away the pages, not the rows
     * somebody saved about them.
     */
    private function site(bool $withTheOtherModule): Container
    {
        $this->schemas[] = Database::schemaFor(self::class, $withTheOtherModule ? 'with' : 'without');

        $container = Boot::container(
            ModuleList::of(
                ['cms' => true, 'crm' => false, 'shop' => $withTheOtherModule],
                Bootstrap::rootDirectory(),
            ),
            config: ['services' => ['http.request' => ['factory' => StandInHttpRequest::class]]],
        );
        Migrations::run($container);
        $tenant = Tenants::enter($container, 'Ammonite Bikes', Tenants::HOST);

        $pages = $container->getByType(Pages::class);
        $home = $pages->create('About us', 'about-us');
        $pages->publish($home);

        $unfinished = $pages->create('Not ready', 'not-ready');

        $entries = $container->getByType(MenuRepository::class);
        $entries->save(MenuItem::toRoute($tenant, MenuItem::MAIN, 'Home', self::IN_CORE, 10));
        $entries->save($this->toPage($tenant, 'About us', $home, 20));
        $entries->save(MenuItem::toRoute($tenant, MenuItem::MAIN, 'Shop', self::IN_ANOTHER_MODULE, 30));
        $entries->save($this->toPage($tenant, 'Not ready', $unfinished, 40));

        return $container;
    }

    private function toPage(Tenant $tenant, string $label, Page $page, int $position): MenuItem
    {
        return MenuItem::toPage($tenant, MenuItem::MAIN, $label, $page, $position);
    }
}
