<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Tests\Architecture\BaseCssHoldsNoLiteralsTest;

/**
 * Every theme declares the same tokens.
 *
 * A token one theme has and another has not is how a component quietly becomes
 * theme-specific: it works, nobody notices, and then the second theme renders
 * that one rule against nothing. The failure is invisible - an unset custom
 * property is not an error, it is an empty value - so it has to be caught here
 * rather than in a browser.
 *
 * It is also what keeps decision D6 honest. The second theme moves the
 * navigation by re-declaring --layout-shell-areas, and it can only do that
 * because the first theme declared it in the first place.
 */
#[CoversNothing]
final class ThemesDeclareTheSameTokensTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function themes(): iterable
    {
        foreach (BaseCssHoldsNoLiteralsTest::themeFiles() as $name => $source) {
            yield $name => [$name, $source];
        }
    }

    public function testThereIsMoreThanOne(): void
    {
        self::assertGreaterThan(
            1,
            count(BaseCssHoldsNoLiteralsTest::themeFiles()),
            'one theme is indistinguishable from hard-coded CSS and proves nothing (decision D6)',
        );
    }

    #[DataProvider('themes')]
    public function testItDeclaresTheSameTokensAsEveryOther(string $name, string $source): void
    {
        $mine = $this->tokensIn($source);
        self::assertNotSame([], $mine, $name . ' declares no token');

        foreach (BaseCssHoldsNoLiteralsTest::themeFiles() as $other => $otherSource) {
            if ($other === $name) {
                continue;
            }

            self::assertSame(
                $this->tokensIn($otherSource),
                $mine,
                sprintf('the themes %s and %s do not declare the same set of tokens', $name, $other),
            );
        }
    }

    /**
     * The second theme has to move the navigation, not merely repaint it. These
     * are the tokens the page shell is built out of in assets/base.css, and a
     * theme that gave them all the same values would pass every other check
     * here while proving nothing.
     */
    public function testAtLeastOneThemeLaysThePageOutDifferently(): void
    {
        $areas = [];
        foreach (BaseCssHoldsNoLiteralsTest::themeFiles() as $name => $source) {
            if (preg_match('/--layout-shell-areas\s*:\s*([^;]+);/', $source, $match) === 1) {
                $areas[$name] = trim($match[1]);
            }
        }

        self::assertGreaterThan(
            1,
            count(array_unique($areas)),
            'every theme lays the shell out the same way, so nothing here would catch markup '
            . 'that had its appearance written into it (decision D6)',
        );
    }

    /**
     * The names a theme file declares, sorted, so that two themes are compared
     * on what they declare rather than on the order somebody typed it in.
     *
     * @return list<string>
     */
    private function tokensIn(string $source): array
    {
        preg_match_all('/^\s*(--[a-z0-9-]+)\s*:/mi', $source, $matches);

        $tokens = array_values(array_unique($matches[1]));
        sort($tokens);

        return $tokens;
    }
}
