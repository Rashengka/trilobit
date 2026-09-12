<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\ComponentRegistry;
use Trilobit\Core\Presentation\Component\Icon;

/**
 * c-icon draws every icon it names, refuses one it does not, and is either
 * silent or named - never a picture a screen reader announces as nothing.
 *
 * The list of icons is an enum rather than the branches of a template, because a
 * template cannot refuse anything: a name nobody drew would be an empty place in
 * the page, which looks exactly like an icon that is there and too faint to see.
 * With the enum the unknown name stops the page, and this test is what keeps
 * the enum and the drawings from parting company - a case with no drawing is the
 * same empty place again.
 */
#[CoversClass(Icon::class)]
final class IconTest extends TestCase
{
    /** @return iterable<string, array{Icon}> */
    public static function everyIcon(): iterable
    {
        foreach (Icon::cases() as $icon) {
            yield $icon->value => [$icon];
        }
    }

    #[DataProvider('everyIcon')]
    public function testItIsDrawn(Icon $icon): void
    {
        $svg = $this->svgIn($this->draw(sprintf("{include icon, name: '%s'}", $icon->value)));

        self::assertNotNull(
            $svg->querySelector('path, line, polyline, polygon, circle, rect'),
            sprintf("'%s' is an icon c-icon names and does not draw", $icon->value),
        );
    }

    /**
     * Every icon is a variant on the style guide, so a new one cannot arrive
     * without somebody having looked at it in both themes.
     */
    public function testTheStyleGuideShowsEveryIcon(): void
    {
        self::assertSame(
            array_map(static fn(Icon $icon): string => $icon->value, Icon::cases()),
            new ComponentRegistry()->find('c-icon')?->variants,
        );
    }

    /** It takes the colour of the text around it, which is what lets a theme recolour it. */
    #[DataProvider('everyIcon')]
    public function testItsInkIsTheTextAroundIt(Icon $icon): void
    {
        $svg = $this->svgIn($this->draw(sprintf("{include icon, name: '%s'}", $icon->value)));

        self::assertSame('currentColor', $svg->getAttribute('stroke'));
        self::assertSame('none', $svg->getAttribute('fill'));
    }

    /** Beside a word that already says what it means, an icon says nothing. */
    public function testWithoutANameItIsHiddenFromAssistiveTechnology(): void
    {
        $svg = $this->svgIn($this->draw("{include icon, name: 'sign-out'}"));

        self::assertSame('true', $svg->getAttribute('aria-hidden'));
        self::assertNull($svg->getAttribute('role'));
        self::assertSame('false', $svg->getAttribute('focusable'));
    }

    /** On its own it has to say what it is. */
    public function testWithANameItIsAnnouncedAsAnImage(): void
    {
        $svg = $this->svgIn($this->draw("{include icon, name: 'sign-out', label: 'Sign out'}"));

        self::assertSame('img', $svg->getAttribute('role'));
        self::assertSame('Sign out', $svg->getAttribute('aria-label'));
        self::assertNull($svg->getAttribute('aria-hidden'));
    }

    public function testAnIconNobodyDrewIsRefused(): void
    {
        $this->expectException(\ValueError::class);

        $this->draw("{include icon, name: 'a-shape-nobody-drew'}");
    }

    private function draw(string $call): HTMLDocument
    {
        return ComponentRendering::render('icon.latte', $call);
    }

    private function svgIn(HTMLDocument $drawn): Element
    {
        $svg = $drawn->querySelector('svg.c-icon');
        self::assertNotNull($svg, 'c-icon drew no svg.c-icon');

        return $svg;
    }
}
