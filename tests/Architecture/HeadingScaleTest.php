<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Decision from 2026-09-12 (.ai/plans/01d-design-system.md, the owner's
 * decision recorded under that date): h6 reads the same size as running
 * text. A heading smaller
 * than the text under it reads as a caption rather than as a heading, and h5
 * already sits at that size - so the two levels are level with each other on
 * purpose, not by accident of the scale.
 *
 * Checked on the file rather than on a rendered page, for the same reason
 * BaseCssHoldsNoLiteralsTest is: which token a heading level reads settles
 * its computed size in every theme at once (D2), so there is nothing a
 * render would show that the declaration itself does not already say.
 */
#[CoversNothing]
final class HeadingScaleTest extends TestCase
{
    public function testH6ReadsTheBodyTextSize(): void
    {
        self::assertMatchesRegularExpression(
            '/h5\s*,\s*h6\s*\{\s*font-size:\s*var\(--text-base\)|h6\s*\{\s*font-size:\s*var\(--text-base\)/',
            BaseCssHoldsNoLiteralsTest::declarations(),
            'h6 must read --text-base, the size running text is set at, and not a size below it',
        );
    }

    /**
     * Written to survive either shape of the rule: one selector list sharing a
     * declaration, or two separate rules that happen to agree. What matters is
     * the size each level resolves to, not how the file spells it.
     */
    public function testH5AndH6ShareTheSameSize(): void
    {
        $css = BaseCssHoldsNoLiteralsTest::declarations();

        self::assertSame(
            $this->fontSizeTokenOf('h5', $css),
            $this->fontSizeTokenOf('h6', $css),
            'h5 and h6 are decided to be the same size (2026-09-12)',
        );
    }

    private function fontSizeTokenOf(string $selector, string $css): string
    {
        self::assertSame(
            1,
            preg_match('/\b' . $selector . '\b[^{]*\{\s*font-size:\s*(var\(--[a-z0-9-]+\))/', $css, $match),
            sprintf('%s has no font-size rule in base.css', $selector),
        );

        return $match[1] ?? self::fail(sprintf('%s matched but captured nothing', $selector));
    }
}
