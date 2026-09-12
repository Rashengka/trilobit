<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Styleguide\StyleguidePage;
use Trilobit\Core\Routing\StyleguideRoutes;

/**
 * Every page of the style guide is drawn in the same frame: the menu of every
 * page down the side, the switch at the top, and the front page as the way
 * into all of them.
 *
 * All three are drawn out of Trilobit\Core\Presentation\Styleguide\StyleguidePages
 * - the list the routes are made from - and this is what holds them to it. A
 * menu written by hand would go on offering a page that moved and leave out
 * the page that was added, and nothing would say so until somebody clicked.
 *
 * The switch is here because it used to live on the one page the guide was,
 * and a specimen in the dark mode is a specimen somebody has to be able to
 * switch to on whichever page it now lives on. It is drawn once on each page
 * and never twice: the page whose specimen is the switch itself draws no
 * second one, and the page that insists on a width draws none at all, because
 * a control showing the setting beside a page not drawn at it is a question
 * nobody needs to be asked.
 */
#[CoversNothing]
final class StyleguideFrameTest extends TestCase
{
    private const string FRONT_PAGE = '/' . StyleguideRoutes::PATH;

    /** The front page first, then every listed page in the order the list has them. */
    public function testEveryPageCarriesTheMenuOfEveryPage(): void
    {
        $expected = [self::FRONT_PAGE, ...array_keys($this->listed())];

        foreach (StyleguideSpecimens::everyPage() as $path => $page) {
            self::assertSame(
                $expected,
                $this->pathsLinkedFrom($this->menuOf($page, $path)),
                sprintf('the menu on %s does not lead to exactly the pages the style guide lists', $path),
            );
        }
    }

    public function testTheMenuMarksThePageItIsOnAndNoOther(): void
    {
        foreach (StyleguideSpecimens::everyPage() as $path => $page) {
            $current = $this->menuOf($page, $path)->querySelectorAll('[aria-current="page"]');

            self::assertCount(1, $current, sprintf('the menu on %s marks other than exactly one page', $path));
            self::assertSame($path, $this->pathOf($current->item(0)), sprintf('the menu on %s marks another page', $path));
        }
    }

    public function testEveryPageCarriesTheSwitchOnceExceptThePageThatInsistsOnAWidth(): void
    {
        $listed = $this->listed();

        foreach (StyleguideSpecimens::everyPage() as $path => $page) {
            $insists = ($listed[$path] ?? null)?->width !== null;

            self::assertCount(
                $insists ? 0 : 1,
                $page->querySelectorAll('[data-preference="theme"][data-preference-value="atrium"]'),
                $insists
                    ? sprintf('%s insists on a width and still carries a switch showing the setting', $path)
                    : sprintf('%s does not carry the switch exactly once', $path),
            );
        }
    }

    /** The front page is the way into every page, drawn with c-signpost. */
    public function testTheFrontPageLeadsToEveryPage(): void
    {
        $front = StyleguideSpecimens::everyPage()[self::FRONT_PAGE] ?? null;
        self::assertNotNull($front, 'the style guide has no front page');

        $linked = [];
        foreach ($front->querySelectorAll('[data-testid="styleguide-signposts"] .c-signpost a[href]') as $link) {
            $linked[] = $this->pathOf($link);
        }

        self::assertSame(array_keys($this->listed()), $linked);
    }

    /** @return array<string, StyleguidePage> keyed by the path each answers at */
    private function listed(): array
    {
        $listed = [];
        foreach (StyleguideSpecimens::pages()->pages() as $page) {
            $listed[self::FRONT_PAGE . '/' . $page->path()] = $page;
        }

        return $listed;
    }

    private function menuOf(HTMLDocument $page, string $path): Element
    {
        $menu = $page->querySelector('[data-testid="styleguide-menu"]');
        self::assertNotNull($menu, sprintf('%s carries no menu of the style guide', $path));

        return $menu;
    }

    /** @return list<string> */
    private function pathsLinkedFrom(Element $menu): array
    {
        $paths = [];
        foreach ($menu->querySelectorAll('a[href]') as $link) {
            $paths[] = $this->pathOf($link);
        }

        return $paths;
    }

    private function pathOf(?Element $link): string
    {
        $path = parse_url($link?->getAttribute('href') ?? '', PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }
}
