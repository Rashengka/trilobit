<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Decision D1, as a mechanism rather than an intention: assets/base.css holds
 * structure, and every value it uses comes out of a token.
 *
 * One colour written straight into a component "just for now" looks harmless
 * every single time, and a theme cannot overrule it. A month of that and the
 * second theme stops working while everybody still believes in the split. So it
 * is checked, and checked on the file rather than on the build output: what a
 * person edits is what has to be right.
 *
 * Comments are removed before anything is looked for, so that the file may go on
 * explaining the rule using the very patterns the rule forbids - which is what
 * the docblock at the top of base.css does.
 *
 * What counts as a value: a colour in any notation, a named colour, and a number
 * carrying an absolute or viewport-relative unit. What does not: zero, which has
 * no unit to theme, and ratios such as 100%, 1fr and 16 / 9, which describe how
 * parts relate to each other rather than how big anything is - "as wide as its
 * container" is true in every theme.
 *
 * Prove it works by writing `color: #ff0000;` into a rule in base.css and
 * watching this fail; then take it out again.
 */
#[CoversNothing]
final class BaseCssHoldsNoLiteralsTest extends TestCase
{
    private const string FILE = 'assets/base.css';

    /**
     * Colour notations. `light-dark()` is here too: it takes two colours, so a
     * declaration using it is a declaration choosing them.
     */
    private const array COLOUR_FUNCTIONS = [
        'rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'lab', 'lch', 'oklab', 'oklch',
        'color', 'color-mix', 'light-dark',
    ];

    /**
     * Enough named colours to catch somebody reaching for one. `transparent`
     * and `currentColor` are deliberately absent: neither names a colour, they
     * name the absence of one and "whatever the cascade already decided".
     */
    private const array NAMED_COLOURS = [
        'white', 'black', 'red', 'green', 'blue', 'yellow', 'orange', 'purple',
        'pink', 'brown', 'grey', 'gray', 'silver', 'gold', 'navy', 'teal',
        'olive', 'maroon', 'lime', 'aqua', 'cyan', 'magenta', 'fuchsia',
        'beige', 'ivory', 'khaki', 'coral', 'salmon', 'tan', 'wheat',
    ];

    /** Units that mean a size somebody chose, rather than a proportion. */
    private const array ABSOLUTE_UNITS = [
        'px', 'pt', 'pc', 'cm', 'mm', 'in', 'q',
        'rem', 'em', 'ch', 'ex', 'cap', 'ic', 'lh', 'rlh',
        'vh', 'vw', 'vmin', 'vmax', 'vb', 'vi',
        'dvh', 'dvw', 'svh', 'svw', 'lvh', 'lvw',
    ];

    /** @return iterable<string, array{string, string}> label => [pattern, advice] */
    public static function forbidden(): iterable
    {
        yield 'a hexadecimal colour' => [
            '/#[0-9a-fA-F]{3,8}\b/',
            'declare it in assets/themes/ and refer to the token here',
        ];

        yield 'a colour notation' => [
            '/\b(?:' . implode('|', self::COLOUR_FUNCTIONS) . ')\s*\(/i',
            'declare it in assets/themes/ and refer to the token here',
        ];

        yield 'a named colour' => [
            '/(?<![\w-])(?:' . implode('|', self::NAMED_COLOURS) . ')(?![\w-])/i',
            'declare it in assets/themes/ and refer to the token here',
        ];

        yield 'a length' => [
            '/(?<![\w.-])\d*\.?\d+(?:' . implode('|', self::ABSOLUTE_UNITS) . ')(?![\w-])/i',
            'a size is a decision a theme has to be able to make, so declare it in assets/themes/',
        ];
    }

    #[DataProvider('forbidden')]
    public function testItHoldsNone(string $pattern, string $advice): void
    {
        $found = preg_match_all($pattern, self::declarations(), $matches);
        self::assertNotFalse($found, 'the pattern itself is broken: ' . $pattern);

        self::assertSame(
            0,
            $found,
            sprintf(
                '%s must not carry a value of its own; found %s. Advice: %s.',
                self::FILE,
                implode(', ', array_unique($matches[0])),
                $advice,
            ),
        );
    }

    /**
     * Every token base.css reads has to be declared by every theme, or the file
     * is written against something one theme happens to have. The themes are
     * compared with each other in
     * Trilobit\Tests\Template\ThemesDeclareTheSameTokensTest; this is the third
     * side of the triangle.
     *
     * A token base.css declares itself is left out, and the exemption gives
     * nothing away. Structure may derive a token from other tokens -
     * --layout-content-width is one of the theme's three widths, picked by the
     * attribute on <html> - and what that derivation reads is still a var() and
     * still checked here. What it may not do is give the token a value, because
     * a value is a literal and the rules above catch those first.
     */
    public function testEveryTokenItReadsIsDeclaredByEveryTheme(): void
    {
        preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', self::declarations(), $matches);
        preg_match_all('/^\s*(--[a-z0-9-]+)\s*:/mi', self::declarations(), $declaredHere);

        $used = array_values(array_diff(array_unique($matches[1]), $declaredHere[1]));
        self::assertNotSame([], $used, self::FILE . ' reads no token at all');

        foreach (self::themeFiles() as $theme => $source) {
            preg_match_all('/^\s*(--[a-z0-9-]+)\s*:/mi', $source, $declared);

            foreach ($used as $token) {
                self::assertContains(
                    $token,
                    $declared[1],
                    sprintf('%s reads %s and the theme %s does not declare it', self::FILE, $token, $theme),
                );
            }
        }
    }

    /** @return array<string, string> theme name => its source */
    public static function themeFiles(): array
    {
        $themes = [];
        $files = glob(dirname(__DIR__, 2) . '/assets/themes/*.css');

        foreach ($files === false ? [] : $files as $file) {
            $themes[pathinfo($file, PATHINFO_FILENAME)] = FileSystem::read($file);
        }

        return $themes;
    }

    /**
     * The file with its comments taken out, which is what the rules apply to.
     *
     * Public because Trilobit\Tests\Template\ComponentRegistryTest asks the
     * same file whether every registered component has a rule - the other half
     * of "base.css holds the shape of things", and the half that stops this
     * file being emptied to make the rules above pass.
     */
    public static function declarations(): string
    {
        $source = FileSystem::read(dirname(__DIR__, 2) . '/' . self::FILE);

        return (string) preg_replace('#/\*.*?\*/#s', '', $source);
    }
}
