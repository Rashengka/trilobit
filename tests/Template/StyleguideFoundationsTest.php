<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Styleguide\OverviewPresenter;
use Trilobit\Core\Presentation\Styleguide\StyleguidePage;
use Trilobit\Core\Routing\StyleguideRoutes;
use Trilobit\Tests\Architecture\BaseCssHoldsNoLiteralsTest;

/**
 * The two things the style guide shows that belong to no register, still shown
 * after the guide was split into pages.
 *
 * A component and a content group are held by the gates of decision D5 wherever
 * they are drawn. The colour tokens and the page that insists on a width of its
 * own are not in either register, so a page split that dropped them on the way
 * would pass every other check - the swatches would simply stop being drawn,
 * and the page drawn at a width of its own would stay reachable only by
 * somebody who already knew its address. Both are asked of every page the
 * router sends to the guide, the same way the gates ask, so that where they
 * live is the list's business and not this test's.
 */
#[CoversNothing]
final class StyleguideFoundationsTest extends TestCase
{
    /** Every token the guide lists as a swatch is drawn, once, on some page of it. */
    public function testEveryColourTokenIsDrawnOnceSomewhereInTheGuide(): void
    {
        foreach (array_keys($this->colourTokens()) as $token) {
            $drawnOn = [];
            foreach (StyleguideSpecimens::everyPage() as $path => $page) {
                foreach ($page->querySelectorAll(sprintf('[data-testid="swatch-%s"]', $token)) as $swatch) {
                    $drawnOn[] = $path . ' (' . $swatch->tagName . ')';
                }
            }

            self::assertCount(
                1,
                $drawnOn,
                sprintf(
                    '%s is a colour token the style guide lists and it is drawn %d times rather than once: %s',
                    $token,
                    count($drawnOn),
                    implode(', ', $drawnOn),
                ),
            );
        }
    }

    /**
     * Every layer a theme names for what is drawn over the page is named, once,
     * on some page of the guide.
     *
     * The layers are read from the theme files rather than from a list kept
     * beside the page, so a layer added to the themes and to nothing else fails
     * here - the guide shows the stacking order the themes declare, not the one
     * somebody remembered to write down (.ai/plans/13-styleguide-layout.md: the
     * guide documents the token once it exists).
     */
    public function testEveryLayerTheThemesDeclareIsNamedOnceSomewhereInTheGuide(): void
    {
        $layers = [];
        foreach (BaseCssHoldsNoLiteralsTest::themeFiles() as $source) {
            preg_match_all('/^\s*(--layout-z-[a-z0-9-]+)\s*:/mi', $source, $matches);
            $layers = [...$layers, ...$matches[1]];
        }

        $layers = array_values(array_unique($layers));
        self::assertNotSame([], $layers, 'no theme declares a layer (--layout-z-*), so there is nothing to look for');

        foreach ($layers as $layer) {
            $namedOn = [];
            foreach (StyleguideSpecimens::everyPage() as $path => $page) {
                foreach ($page->querySelectorAll(sprintf('[data-testid="chrome-token-%s"]', $layer)) as $row) {
                    $namedOn[] = $path . ' (' . $row->tagName . ')';
                }
            }

            self::assertCount(
                1,
                $namedOn,
                sprintf(
                    '%s is a layer the themes declare and the style guide names it %d times rather than once: %s',
                    $layer,
                    count($namedOn),
                    implode(', ', $namedOn),
                ),
            );
        }
    }

    /**
     * The page that insists on a width is pointed at from what a page of the
     * guide says, and not only from the menu: it is the one specimen that is a
     * whole page, and it is only worth anything next to the words saying what
     * it shows.
     */
    public function testThePageThatInsistsOnAWidthIsPointedAtFromTheTextOfAPage(): void
    {
        $insisting = $this->insisting();

        $pointedFrom = [];
        foreach (StyleguideSpecimens::everyPage() as $path => $page) {
            foreach ($page->querySelectorAll('.sg-frame__main a[href]') as $link) {
                if ($link->closest('[data-testid="styleguide-signposts"]') instanceof Element) {
                    continue;
                }

                if (parse_url($link->getAttribute('href') ?? '', PHP_URL_PATH) === $insisting) {
                    $pointedFrom[] = $path;
                }
            }
        }

        self::assertNotSame(
            [],
            $pointedFrom,
            sprintf('no page of the style guide says a word about %s; only its menu leads there', $insisting),
        );
    }

    private function insisting(): string
    {
        $insisting = array_values(array_filter(
            StyleguideSpecimens::pages()->pages(),
            static fn(StyleguidePage $page): bool => $page->width !== null,
        ));
        self::assertCount(1, $insisting, 'the style guide has to have exactly one page that insists on a width');

        return '/' . StyleguideRoutes::PATH . '/' . $insisting[0]->path();
    }

    /**
     * The tokens the guide shows, read from where the guide keeps them rather
     * than written out a second time here.
     *
     * @return array<mixed>
     */
    private function colourTokens(): array
    {
        $tokens = new \ReflectionClassConstant(OverviewPresenter::class, 'COLOUR_TOKENS')->getValue();
        self::assertIsArray($tokens);
        self::assertNotSame([], $tokens);

        return $tokens;
    }
}
