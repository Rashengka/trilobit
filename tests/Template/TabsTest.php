<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\HeadingLevel;

/**
 * c-tabs as the server draws it, which is the page somebody gets when the
 * script does not arrive: every panel one under another, each under its
 * title, in the order the caller named them.
 *
 * The tab list, the roles, the keys and the hiding are assets/tabs.ts's, laid
 * over this markup once the page has loaded, and they are measured in a
 * browser (tests/e2e/tabs.spec.ts). What is held here is that the markup
 * claims none of them: a role="tab" the server wrote would announce a control
 * that does nothing until a script turns up, and a panel it hid would be
 * content nobody without the script could reach.
 *
 * Children are looked up by walking them rather than with :scope, which the
 * DOM parser of PHP 8.4 - the floor of require.php - does not understand.
 */
#[CoversNothing]
final class TabsTest extends TestCase
{
    private const string CALL = "{embed block tabs, label: 'Care of the specimen', panels: ['cleaning' => 'Cleaning', 'storing' => 'Storing']%s}"
        . '{block cleaning}<p>Dust it.</p>{/block}'
        . '{block storing}<p>In the drawer.</p>{/block}'
        . '{/embed}';

    public function testWithoutTheScriptEveryPanelIsDrawnUnderItsTitleInOrder(): void
    {
        $panels = $this->panels($this->draw());

        self::assertCount(2, $panels);
        self::assertSame(['Cleaning', 'Storing'], array_map($this->titleOf(...), $panels));
        self::assertSame('Dust it.', trim($panels[0]->querySelector('p:not(.c-tabs__title)')->textContent ?? ''));
        self::assertSame('In the drawer.', trim($panels[1]->querySelector('p:not(.c-tabs__title)')->textContent ?? ''));
    }

    /** The name of the tab list is kept for the script, on the element it will put the list into. */
    public function testItKeepsTheNameOfItsTabListForTheScript(): void
    {
        self::assertSame('Care of the specimen', $this->root($this->draw())->getAttribute('data-tabs-label'));
    }

    public function testTheMarkupClaimsNothingTheScriptHasNotMade(): void
    {
        $root = $this->root($this->draw());

        foreach (['[role]', '[aria-selected]', '[aria-controls]', '[aria-labelledby]', '[hidden]', '[tabindex]'] as $selector) {
            self::assertNull($root->querySelector($selector), sprintf('the server drew %s, which is the script\'s to add', $selector));
        }
    }

    /** An address is what a panel can be linked to by, and the caller decides whether there is one. */
    public function testItsPanelsHaveAddressesOnlyWhenGivenAnId(): void
    {
        self::assertSame([false, false], array_map(
            static fn(Element $panel): bool => $panel->hasAttribute('id'),
            $this->panels($this->draw()),
        ));

        self::assertSame(['care-cleaning', 'care-storing'], array_map(
            static fn(Element $panel): string => $panel->getAttribute('id') ?? '',
            $this->panels($this->draw(", id: 'care'")),
        ));
    }

    /** @return iterable<string, array{int}> */
    public static function everyLevel(): iterable
    {
        foreach (HeadingLevel::cases() as $level) {
            yield 'h' . $level->value => [$level->value];
        }
    }

    /**
     * With a level the titles are headings of the page, so that without the
     * script the panels are parts of its outline; the class decides their
     * size, so they look the same at every level.
     */
    #[DataProvider('everyLevel')]
    public function testWithALevelTheTitlesAreHeadings(int $level): void
    {
        foreach ($this->panels($this->draw(sprintf(', level: %d', $level))) as $panel) {
            self::assertSame('H' . $level, $this->titleElementOf($panel)->tagName);
        }
    }

    public function testWithoutALevelTheTitlesAreNoHeadings(): void
    {
        $root = $this->root($this->draw());

        self::assertNull($root->querySelector('h1, h2, h3, h4, h5, h6'));
        foreach ($this->panels($this->draw()) as $panel) {
            self::assertSame('P', $this->titleElementOf($panel)->tagName);
        }
    }

    public function testALevelThatIsNoHeadingOfAPartIsRefused(): void
    {
        foreach ([1, 7] as $level) {
            try {
                $this->draw(sprintf(', level: %d', $level));
                self::fail(sprintf('level %d was drawn', $level));
            } catch (\ValueError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** A panel named and not given is a mistake of the caller's, and says so rather than drawing a title over nothing. */
    public function testAPanelNamedAndNotGivenFailsLoudly(): void
    {
        $this->expectException(\Throwable::class);

        ComponentRendering::render(
            'tabs.latte',
            "{embed block tabs, label: 'Care', panels: ['cleaning' => 'Cleaning', 'lending' => 'Lending']}"
                . '{block cleaning}<p>Dust it.</p>{/block}{/embed}',
        );
    }

    private function draw(string $arguments = ''): HTMLDocument
    {
        return ComponentRendering::render('tabs.latte', sprintf(self::CALL, $arguments));
    }

    private function root(HTMLDocument $page): Element
    {
        $root = $page->querySelector('.c-tabs');
        self::assertInstanceOf(Element::class, $root, 'nothing carries c-tabs');

        return $root;
    }

    /** @return list<Element> the panels directly under the root, in order */
    private function panels(HTMLDocument $page): array
    {
        $panels = [];
        for ($child = $this->root($page)->firstElementChild; $child instanceof Element; $child = $child->nextElementSibling) {
            self::assertSame('c-tabs__panel', $child->className, 'everything directly under c-tabs is a panel');
            $panels[] = $child;
        }

        return $panels;
    }

    /** The panel's title, which has to be the first thing in it: it is what the script names the tab after. */
    private function titleElementOf(Element $panel): Element
    {
        $title = $panel->firstElementChild;
        self::assertInstanceOf(Element::class, $title);
        self::assertSame('c-tabs__title', $title->className);

        return $title;
    }

    private function titleOf(Element $panel): string
    {
        return trim($this->titleElementOf($panel)->textContent ?? '');
    }
}
