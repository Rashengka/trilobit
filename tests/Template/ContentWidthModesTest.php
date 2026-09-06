<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Preference\PreferenceCatalogue;
use Trilobit\Core\Presentation\Design\DesignSystem;
use Trilobit\Tests\Architecture\BaseCssHoldsNoLiteralsTest;

/**
 * Every width this build offers is a width the stylesheet draws.
 *
 * This is the same hole Trilobit\Core\Presentation\Content\ContentGroupRegistry
 * was written for, in its cheapest form. A mode is a value in a list, and a
 * value costs nothing to add: the control appears, the click works, the server
 * stores it and the attribute lands on <html> - and if no rule answers that
 * attribute the page is simply drawn at whatever the one before it was. Nothing
 * is empty, nothing throws, nothing is logged. So the list is checked against
 * assets/base.css from here.
 *
 * It closes only its own half. That base.css names --layout-width-<mode> is
 * checked here; that every theme declares it is
 * Trilobit\Tests\Architecture\BaseCssHoldsNoLiteralsTest, and that the themes
 * declare the same set as each other is
 * Trilobit\Tests\Template\ThemesDeclareTheSameTokensTest. Three sides, and a
 * mode added to the catalogue alone fails on the first of them.
 *
 * The other half of the mode - that somebody can reach it - is
 * Trilobit\Tests\Template\StyleguideOffersEveryPreferenceTest.
 */
#[CoversNothing]
final class ContentWidthModesTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function modes(): iterable
    {
        foreach (self::catalogue()->preference(PreferenceCatalogue::CONTENT_WIDTH)->values as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('modes')]
    public function testTheStylesheetDrawsIt(string $mode): void
    {
        self::assertSame(
            [],
            $this->undrawnIn(BaseCssHoldsNoLiteralsTest::declarations(), [$mode]),
            sprintf(
                "'%s' is a content width this build offers and assets/base.css has no rule taking "
                . "[data-content-width='%s'] to var(--layout-width-%s), so choosing it would change "
                . 'the attribute and nothing else.',
                $mode,
                $mode,
                $mode,
            ),
        );
    }

    /**
     * The rule above reports nothing, so it would read exactly the same if it
     * were looking in the wrong place or for the wrong thing. Here it is run
     * over a stylesheet carrying the mistake it exists to catch, and has to name
     * it.
     */
    public function testTheRuleReportsAModeTheStylesheetDoesNotDraw(): void
    {
        $css = <<<'CSS'
            :root[data-content-width='content'] {
                --layout-content-width: var(--layout-width-content);
            }

            :root[data-content-width='full'] {
                --layout-content-width: var(--layout-width-full);
            }
            CSS;

        self::assertSame(['wide'], $this->undrawnIn($css, ['content', 'wide', 'full']));
    }

    /**
     * And a rule that mentions the mode without doing anything with it is not
     * enough either: the selector alone is what somebody writes when they copy
     * the one above and forget to change the token.
     */
    public function testTheRuleReportsASelectorThatReadsTheWrongToken(): void
    {
        $css = <<<'CSS'
            :root[data-content-width='wide'] {
                --layout-content-width: var(--layout-width-content);
            }
            CSS;

        self::assertSame(['wide'], $this->undrawnIn($css, ['wide']));
    }

    /**
     * The modes the stylesheet has no rule for.
     *
     * A rule counts when its selector carries the attribute at that value and
     * its body takes --layout-content-width from the token of the same name.
     * Both halves matter: the selector is how the mode is reached and the token
     * is what makes the mode a decision the theme gets to make.
     *
     * @param list<string> $modes
     *
     * @return list<string>
     */
    private function undrawnIn(string $css, array $modes): array
    {
        $undrawn = [];
        foreach ($modes as $mode) {
            $pattern = sprintf(
                '/\[data-content-width=[\'"]%1$s[\'"]\][^{}]*\{[^{}]*'
                . '--layout-content-width\s*:\s*var\(\s*--layout-width-%1$s\s*\)/',
                preg_quote($mode, '/'),
            );

            if (preg_match($pattern, $css) !== 1) {
                $undrawn[] = $mode;
            }
        }

        return $undrawn;
    }

    private static function catalogue(): PreferenceCatalogue
    {
        return PreferenceCatalogue::of(DesignSystem::of(Bootstrap::rootDirectory(), 'atrium'));
    }
}
