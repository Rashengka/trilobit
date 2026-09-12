<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration;

use Dom\HTMLDocument;
use Nette\DI\Container;
use Nette\Routing\Router;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Presentation\Styleguide\StyleguidePages;
use Trilobit\Core\Routing\StyleguideRoutes;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Combination\Build;
use Trilobit\Tests\Template\StyleguideSpecimens;

/**
 * Decision D4: the style guide is a page of the application, and switching it
 * off takes the page away rather than guarding it.
 *
 * The distinction matters. A guarded page answers 403, which tells a visitor
 * there is something here worth coming back for; a page that was never
 * registered answers 404, which tells them nothing, because there is nothing to
 * tell. The mechanism is the same one a switched-off module uses - no service,
 * therefore no route, and the router has no catch-all to answer in its place -
 * so this suite asks the same question of it: does anybody claim the path.
 */
#[CoversNothing]
final class StyleguidePageTest extends TestCase
{
    private const string PATH = '/' . StyleguideRoutes::PATH;

    public function testWithTheSwitchOffNobodyClaimsThePath(): void
    {
        self::assertNull(
            Build::match($this->container(styleguide: false), self::PATH),
            'the style guide is switched off and its path is still routed, so it would answer rather than 404',
        );
    }

    /**
     * All of it goes, and not only its front page: a page of the guide left
     * routed in a build without the guide would answer at an address nobody
     * links to, which is the worst place for a page to be.
     */
    public function testWithTheSwitchOffNoPageOfTheGuideIsRouted(): void
    {
        $container = $this->container(styleguide: false);

        foreach ($container->getByType(StyleguidePages::class)->pages() as $page) {
            self::assertNull(
                Build::match($container, self::PATH . '/' . $page->path()),
                sprintf('the style guide is switched off and its page %s is still routed', $page->path()),
            );
        }

        self::assertSame([], StyleguideSpecimens::pathsIn($container->getByType(Router::class)));
    }

    /** Every page the list has answers at the address the list gives it, and is told which page it is. */
    public function testWithTheSwitchOnEveryListedPageIsRoutedToItself(): void
    {
        $container = $this->container(styleguide: true);

        foreach ($container->getByType(StyleguidePages::class)->pages() as $page) {
            $match = Build::match($container, self::PATH . '/' . $page->path());
            self::assertNotNull($match, sprintf('%s is a page of the style guide and nothing routes it', $page->path()));

            self::assertSame(
                ['presenter' => 'Core:Styleguide:Overview', 'action' => 'page', 'group' => $page->group, 'page' => $page->slug],
                array_intersect_key($match, array_flip(['presenter', 'action', 'group', 'page'])),
            );
        }
    }

    /**
     * And the other way round: the guide answers at its front page and at the
     * pages the list has, and at nothing else - so there is no route without a
     * page and no page that the gates, which read the routes, would not see.
     */
    public function testTheGuideAnswersAtItsFrontPageAndItsListedPagesAndNowhereElse(): void
    {
        $container = $this->container(styleguide: true);

        $expected = [self::PATH];
        foreach ($container->getByType(StyleguidePages::class)->pages() as $page) {
            $expected[] = self::PATH . '/' . $page->path();
        }

        $routed = StyleguideSpecimens::pathsIn($container->getByType(Router::class));

        sort($expected);
        sort($routed);
        self::assertSame($expected, $routed);
    }

    public function testWithTheSwitchOffThereIsNoLinkToIt(): void
    {
        $document = HTMLDocument::createFromString(
            Build::render($this->container(styleguide: false), 'Core:Front:Home'),
            LIBXML_NOERROR,
        );

        self::assertNull(
            $document->querySelector('[data-testid="signpost-styleguide"]'),
            'a link to a page this build has no route for is a link to a 404',
        );
        self::assertNull($document->querySelector('[data-testid="nav-styleguide"]'));
    }

    public function testWithTheSwitchOnThePathReachesThePage(): void
    {
        $match = Build::match($this->container(styleguide: true), self::PATH);

        self::assertNotNull($match);
        self::assertSame('Core:Styleguide:Overview', $match['presenter'] ?? null);
    }

    public function testItRendersInsideTheSharedLayout(): void
    {
        $document = HTMLDocument::createFromString(
            Build::render($this->container(styleguide: true), 'Core:Styleguide:Overview'),
            LIBXML_NOERROR,
        );

        // The same chrome as every other page: that is the whole point of D4,
        // and the reason a component that broke here would have broken there.
        self::assertNotNull($document->querySelector('[data-testid="layout"]'));
        self::assertNotNull($document->querySelector('[data-testid="layout-nav"]'));
        self::assertSame(
            'Style guide',
            $document->querySelector('[data-testid="styleguide-headline"]')?->textContent,
        );
    }

    /**
     * The switcher offers every theme this installation has, so adding a file
     * to assets/themes/ is the whole of adding a theme.
     */
    public function testItOffersEveryThemeThereIs(): void
    {
        $document = HTMLDocument::createFromString(
            Build::render($this->container(styleguide: true), 'Core:Styleguide:Overview'),
            LIBXML_NOERROR,
        );

        $offered = [];
        foreach ($document->querySelectorAll('[data-preference="theme"]') as $choice) {
            $offered[] = $choice->getAttribute('data-preference-value');
        }

        $files = glob(Bootstrap::rootDirectory() . '/assets/themes/*.css');
        $themes = array_map(
            static fn(string $file): string => pathinfo($file, PATHINFO_FILENAME),
            $files === false ? [] : $files,
        );
        sort($themes);

        self::assertSame($themes, $offered);
    }

    private function container(bool $styleguide): Container
    {
        return Boot::container(
            ModuleList::of(['cms' => true, 'crm' => true, 'shop' => true], Bootstrap::rootDirectory()),
            $styleguide,
        );
    }
}
