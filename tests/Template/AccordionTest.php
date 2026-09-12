<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\ComponentRegistry;

/**
 * c-accordion is a group of c-collapse, and whether one or any number of them
 * may be open is decided by the browser from one attribute: disclosures that
 * share a name are opened one at a time. So the two variants differ in nothing
 * but that attribute, and this holds the style guide's specimens to it - the
 * group that claims to open one at a time shares one name on every item, and
 * the other carries none. What the browser then does is measured in
 * tests/e2e/disclosure.spec.ts.
 */
#[CoversNothing]
final class AccordionTest extends TestCase
{
    private const string COMPONENT = 'c-accordion';

    public function testItHoldsTheCollapsesItIsGivenInTheirOrder(): void
    {
        $drawn = ComponentRendering::render(
            'accordion.latte',
            "{import 'collapse.latte'}\n"
            . '{embed block accordion}{block accordionItems}'
            . "{include collapse, title: 'First', name: 'questions', level: 3}"
            . "{include collapse, title: 'Second', name: 'questions', level: 3}"
            . '{/block}{/embed}',
        );

        $group = $drawn->querySelector('.c-accordion');
        self::assertNotNull($group, 'c-accordion drew nothing carrying .c-accordion');

        // Walked rather than asked with :scope, which PHP 8.4's parser does not know.
        $titles = [];
        for ($item = $group->firstElementChild; $item instanceof Element; $item = $item->nextElementSibling) {
            self::assertSame('DETAILS', $item->tagName, 'the group holds its items and nothing between them');
            $titles[] = trim($item->querySelector('summary')->textContent ?? '');
        }

        self::assertSame(['First', 'Second'], $titles);
    }

    public function testTheStyleGuideShowsEveryVariant(): void
    {
        self::assertSame(
            ['one open at a time', 'any number open'],
            new ComponentRegistry()->find(self::COMPONENT)?->variants,
        );
    }

    public function testTheGroupOpenedOneAtATimeSharesOneNameOnEveryItem(): void
    {
        $names = $this->namesIn('one open at a time');

        self::assertGreaterThan(1, count($names), 'a group of one shows nothing about opening one at a time');
        self::assertCount(1, array_unique($names), 'the items do not share one name: ' . implode(', ', $names));
        self::assertNotSame('', $names[0]);
    }

    public function testTheGroupWithAnyNumberOpenNamesNoItem(): void
    {
        $names = $this->namesIn('any number open');

        self::assertGreaterThan(1, count($names), 'a group of one shows nothing about opening any number');
        self::assertSame([''], array_values(array_unique($names)));
    }

    /** @return list<string> the name of every item of the specimen, '' for an item with none */
    private function namesIn(string $variant): array
    {
        return array_map(
            static fn(Element $item): string => $item->getAttribute('name') ?? '',
            iterator_to_array(
                $this->specimen($variant)->querySelectorAll('.sg-specimen__stage .c-accordion > details'),
                false,
            ),
        );
    }

    /**
     * The specimen as the style guide draws it, found by the section that
     * shows the component rather than by an address written here: the pages
     * and their addresses are kept in Trilobit\Core\Presentation\Styleguide\StyleguidePages,
     * and a second copy of one would be a second thing to keep in step.
     */
    private function specimen(string $variant): Element
    {
        $selector = sprintf(
            '[data-styleguide-component="%s"] [data-styleguide-variant="%s"]',
            self::COMPONENT,
            $variant,
        );

        $found = array_values(array_filter(array_map(
            static fn(HTMLDocument $page): ?Element => $page->querySelector($selector),
            StyleguideSpecimens::everyPage(),
        )));

        self::assertCount(1, $found, sprintf('the style guide does not show the specimen %s of %s once', $variant, self::COMPONENT));

        return $found[0];
    }
}
