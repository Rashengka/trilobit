<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Tests\Architecture\BaseCssHoldsNoLiteralsTest;

/**
 * The foundations every component is drawn out of, declared by every theme,
 * and the colours among them readable on the grounds they are meant for.
 *
 * ThemesDeclareTheSameTokensTest already holds the themes to one set of names,
 * but it holds them to whatever set that is - both themes losing a token passes
 * it. This one names the scales a component may count on: the tones a page
 * reports with, the steps of the type, the axis of control and icon sizes, the
 * corners and the elevations, the curves and the loops of motion, and the
 * widths and layers of a layout.
 *
 * The contrast is computed from the theme files, in both modes of every theme,
 * so a colour changed in one of them is measured before a browser ever draws
 * it. Text has to hold 4.5:1 on its ground (WCAG 2, 1.4.3, level AA); a line
 * that is the edge of a control has to hold 3:1 (1.4.11).
 */
#[CoversNothing]
final class ThemesDeclareTheFoundationsTest extends TestCase
{
    /** The tones a page reports with, each as one family of the same six tokens. */
    public const array TONES = ['success', 'warning', 'info', 'danger'];

    /** What each family holds: the tone itself, then -hover, -on, -surface, -ink and -line. */
    public const array TONE_MEMBERS = ['', '-hover', '-on', '-surface', '-ink', '-line'];

    /** @var list<string> */
    private const array FOUNDATIONS = [
        '--color-ink-subtle',
        '--color-line-strong',
        '--color-surface-raised',
        '--color-surface-active',
        '--color-link',
        '--color-link-hover',
        '--color-accent-active',
        '--color-accent-surface',
        '--color-accent-surface-hover',
        '--color-accent-text',

        '--text-xs',
        '--text-4xl',
        '--leading-xs',
        '--leading-sm',
        '--leading-base',
        '--leading-lg',
        '--leading-xl',
        '--leading-2xl',
        '--leading-3xl',
        '--leading-4xl',
        '--leading-snug',
        '--font-weight-normal',
        '--font-weight-medium',
        '--font-weight-semibold',
        '--font-weight-bold',
        '--tracking-tight',
        '--tracking-normal',
        '--tracking-wide',

        '--control-size-xs',
        '--control-size-sm',
        '--control-size-md',
        '--control-size-lg',
        '--control-icon-xs',
        '--control-icon-sm',
        '--control-icon-md',
        '--control-icon-lg',
        '--icon-size-sm',
        '--icon-size-md',
        '--icon-size-lg',

        '--radius-xs',
        '--radius-xl',
        '--shadow-xs',
        '--shadow-sm',
        '--shadow-md',
        '--shadow-lg',

        '--motion-base',
        '--ease-standard',
        '--ease-out',
        '--animate-spin',
        '--animate-ping',
        '--animate-pulse',
        '--animate-sweep',

        '--layout-breakpoint-sm',
        '--layout-breakpoint-md',
        '--layout-breakpoint-lg',
        '--layout-container-xs',
        '--layout-container-sm',
        '--layout-container-md',
        '--layout-container-lg',
        '--layout-container-xl',
        '--layout-aside-size',
        '--layout-auth-card-width',
        '--layout-z-pinned',
        '--layout-z-overlay',
    ];

    /**
     * Text and the grounds it is set on, every pair at 4.5:1.
     *
     * @var list<array{string, string}>
     */
    private const array TEXT_ON_GROUND = [
        ['--color-ink-subtle', '--color-canvas'],
        ['--color-ink-subtle', '--color-surface'],
        ['--color-ink', '--color-surface-raised'],
        ['--color-ink-muted', '--color-surface-raised'],
        ['--color-ink', '--color-surface-active'],
        ['--color-link', '--color-canvas'],
        ['--color-link', '--color-surface'],
        ['--color-link-hover', '--color-canvas'],
        ['--color-link-hover', '--color-surface'],
        ['--color-accent-ink', '--color-accent-active'],
        ['--color-accent-text', '--color-canvas'],
        ['--color-accent-text', '--color-surface'],
        ['--color-accent-text', '--color-accent-surface'],
        ['--color-accent-text', '--color-accent-surface-hover'],
        ['--color-ink', '--color-accent-surface-hover'],
    ];

    /**
     * The edge of a control and the grounds it is drawn on, at 3:1.
     *
     * @var list<array{string, string}>
     */
    private const array EDGE_ON_GROUND = [
        ['--color-line-strong', '--color-canvas'],
        ['--color-line-strong', '--color-surface'],
    ];

    private const float TEXT = 4.5;

    private const float EDGE = 3.0;

    /** @return iterable<string, array{string, string}> */
    public static function themes(): iterable
    {
        foreach (BaseCssHoldsNoLiteralsTest::themeFiles() as $name => $source) {
            yield $name => [$name, $source];
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function themesAndModes(): iterable
    {
        foreach (BaseCssHoldsNoLiteralsTest::themeFiles() as $name => $source) {
            foreach ([ThemeColours::LIGHT, ThemeColours::DARK] as $mode) {
                yield $name . ', ' . $mode => [$name, $source, $mode];
            }
        }
    }

    /** @return list<string> */
    public static function foundations(): array
    {
        $tones = [];
        foreach (self::TONES as $tone) {
            foreach (self::TONE_MEMBERS as $member) {
                $tones[] = '--color-' . $tone . $member;
            }
        }

        return [...self::FOUNDATIONS, ...$tones];
    }

    #[DataProvider('themes')]
    public function testItDeclaresEveryFoundation(string $name, string $source): void
    {
        $colours = ThemeColours::of($source);

        $missing = [];
        foreach (self::foundations() as $token) {
            try {
                $colours->value($token);
            } catch (\InvalidArgumentException) {
                $missing[] = $token;
            }
        }

        self::assertSame([], $missing, sprintf('the theme %s does not declare these foundations', $name));
    }

    /**
     * Light and dark are one theme (decision D7): a colour a theme writes out
     * is a pair, so that adding a mode costs an argument and not a copy. A
     * colour may also be another token of the theme, or no colour at all.
     */
    #[DataProvider('themes')]
    public function testEveryColourItWritesOutIsALightDarkPair(string $name, string $source): void
    {
        $colours = ThemeColours::of($source);

        $single = [];
        foreach ($colours->tokens() as $token) {
            $value = $colours->value($token);
            if (preg_match('/^(light-dark\(|var\(|transparent$)/', $value) !== 1) {
                $single[] = $token . ': ' . $value;
            }
        }

        self::assertSame([], $single, sprintf('the theme %s writes these colours for one mode only', $name));
    }

    #[DataProvider('themesAndModes')]
    public function testTextIsReadableOnItsGround(string $name, string $source, string $mode): void
    {
        $colours = ThemeColours::of($source);

        $pairs = self::TEXT_ON_GROUND;
        foreach (self::TONES as $tone) {
            $pairs[] = ['--color-' . $tone . '-on', '--color-' . $tone];
            $pairs[] = ['--color-' . $tone . '-on', '--color-' . $tone . '-hover'];
            $pairs[] = ['--color-' . $tone . '-ink', '--color-' . $tone . '-surface'];
            $pairs[] = ['--color-' . $tone . '-ink', '--color-canvas'];
            $pairs[] = ['--color-' . $tone . '-ink', '--color-surface'];
        }

        $this->assertEveryPairHolds($colours, $pairs, $mode, self::TEXT, $name);
    }

    #[DataProvider('themesAndModes')]
    public function testTheEdgeOfAControlIsSeenOnItsGround(string $name, string $source, string $mode): void
    {
        $this->assertEveryPairHolds(ThemeColours::of($source), self::EDGE_ON_GROUND, $mode, self::EDGE, $name);
    }

    /**
     * The measurement itself, on colours whose contrast is known: black on
     * white is 21:1, a colour on itself 1:1. Without this, a reading that
     * came out the same for everything would pass every pair above.
     */
    public function testTheMeasurementGivesTheKnownRatios(): void
    {
        $colours = ThemeColours::of(<<<'CSS'
            --color-white: light-dark(oklch(1 0 0), oklch(0% 0 0));
            --color-black: light-dark(oklch(0 0 0), oklch(100% 0 0));
            --color-alias: var(--color-white);
            CSS);

        self::assertEqualsWithDelta(21.0, $colours->contrast('--color-white', '--color-black', ThemeColours::LIGHT), 0.01);
        self::assertEqualsWithDelta(21.0, $colours->contrast('--color-alias', '--color-black', ThemeColours::DARK), 0.01);
        self::assertEqualsWithDelta(1.0, $colours->contrast('--color-white', '--color-alias', ThemeColours::LIGHT), 0.01);

        $this->expectException(\InvalidArgumentException::class);
        ThemeColours::of('--color-named: red;')->resolve('--color-named', ThemeColours::LIGHT);
    }

    /**
     * @param list<array{string, string}> $pairs
     */
    private function assertEveryPairHolds(ThemeColours $colours, array $pairs, string $mode, float $least, string $name): void
    {
        $below = [];
        foreach ($pairs as [$one, $two]) {
            $ratio = $colours->contrast($one, $two, $mode);
            if ($ratio < $least) {
                $below[] = sprintf('%s on %s: %.2f:1', $one, $two, $ratio);
            }
        }

        self::assertSame([], $below, sprintf('%s, %s: below %.1f:1', $name, $mode, $least));
    }
}
