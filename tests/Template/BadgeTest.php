<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * c-badge's third variant: a state that asks for attention - an invoice past
 * its date, a product that ran out, an account that was suspended.
 *
 * Its name says what kind of state it is, the same word c-notice uses for a
 * refusal, and a theme decides how loud that looks. That it can be read is
 * measured in a browser in tests/e2e/component-variants.spec.ts.
 */
#[CoversNothing]
final class BadgeTest extends TestCase
{
    public function testAStateThatAsksForAttentionSaysSoByItsVariant(): void
    {
        $badge = ComponentRendering::render('badge.latte', "{include badge, label: 'Overdue', variant: 'danger'}")
            ->querySelector('.c-badge');

        self::assertInstanceOf(Element::class, $badge);
        self::assertSame('c-badge c-badge--danger', $badge->getAttribute('class'));
        self::assertSame('Overdue', $badge->textContent);
    }

    public function testAVariantNobodyDrewIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ComponentRendering::render('badge.latte', "{include badge, label: 'Overdue', variant: 'warning'}");
    }
}
