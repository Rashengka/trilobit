<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The specimen of a submenu shows the case that breaks: an entry that leads
 * somewhere of its own and has entries under it, two levels deep.
 *
 * The gate of decision D5 asks only that a variant is shown under its name,
 * and a specimen of a submenu passes that with any tree at all - including one
 * whose parent is a bare "#", which is the one kind of parent that cannot lose
 * its own click because it has none to lose
 * (.ai/plans/10-menu-submenu-a-rozcestniky.md, M1: the specimen has to hold an
 * entry with entries under it and a target of its own). So the shape is read off
 * the rendered guide: a link with a real address, the button beside it, the list
 * that button names, and the same again one level further down.
 *
 * The rule is kept apart from the reading, and run over made-up specimens that
 * carry exactly the mistakes it exists to catch.
 */
#[CoversNothing]
final class StyleguideShowsANestedNavigationTest extends TestCase
{
    /** The registered variant of c-nav this is about. */
    private const string VARIANT = 'with entries nested under an entry';

    /** How many levels have to hang under an entry that leads somewhere. */
    private const int LEVELS = 2;

    public function testTheSpecimenHasAnEntryLeadingSomewhereWithTwoLevelsUnderIt(): void
    {
        $specimens = [];
        foreach (StyleguideSpecimens::everyPage() as $path => $page) {
            $selector = sprintf(
                '[%s="c-nav"] [%s="%s"]',
                StyleguideSpecimens::COMPONENT,
                StyleguideSpecimens::VARIANT,
                self::VARIANT,
            );

            foreach ($page->querySelectorAll($selector) as $specimen) {
                $specimens[$path] = $specimen;
            }
        }

        self::assertCount(1, $specimens, sprintf('the style guide shows "%s" of c-nav on %d pages', self::VARIANT, count($specimens)));

        self::assertGreaterThanOrEqual(
            self::LEVELS,
            self::deepestUnderALinkedEntry(array_values($specimens)[0]),
            sprintf(
                'the specimen "%s" on %s has no entry that leads somewhere of its own and holds %d levels of '
                . 'entries under it, and that is the case a submenu breaks',
                self::VARIANT,
                array_keys($specimens)[0],
                self::LEVELS,
            ),
        );
    }

    public function testTheRuleCountsBothLevelsOfAWellFormedTree(): void
    {
        self::assertSame(2, self::deepestUnderALinkedEntry($this->specimen($this->tree('/collections', '/fossils'))));
    }

    /**
     * A parent that leads nowhere has no click of its own to lose, so it
     * proves nothing - at the top, where it counts for nothing at all, and one
     * level down, where it stops the count at the level above it.
     */
    public function testTheRuleDoesNotCountAParentThatLeadsNowhere(): void
    {
        self::assertSame(0, self::deepestUnderALinkedEntry($this->specimen($this->tree('#', '/fossils'))));
        self::assertSame(1, self::deepestUnderALinkedEntry($this->specimen($this->tree('/collections', ''))));
    }

    public function testTheRuleDoesNotCountEntriesThatNoButtonOpens(): void
    {
        $markup = str_replace('aria-controls="collections-children"', 'aria-controls="nothing"', $this->tree('/collections', '/fossils'));

        self::assertSame(0, self::deepestUnderALinkedEntry($this->specimen($markup)));
    }

    /**
     * A tree whose only linked parent sits one level down proves less than it
     * looks: the entry a theme opens as a block over the page is a top-level
     * one, and that is where the click has to survive.
     */
    public function testTheRuleCountsOnlyEntriesOfTheTopLevel(): void
    {
        $markup = '<ul><li><a href="#">Top</a><button type="button" aria-controls="top-children">Open</button>'
            . '<ul id="top-children"><li>' . $this->tree('/collections', '/fossils') . '</li></ul></li></ul>';

        self::assertSame(0, self::deepestUnderALinkedEntry($this->specimen($markup)));
    }

    /**
     * The deepest number of levels hanging under a top-level entry whose own
     * link leads somewhere real, and whose own button opens the list they are
     * in.
     */
    public static function deepestUnderALinkedEntry(Element $specimen): int
    {
        $top = $specimen->querySelector('ul');
        if (!$top instanceof Element) {
            return 0;
        }

        $deepest = 0;
        foreach ($top->children as $entry) {
            if ($entry->localName === 'li') {
                $deepest = max($deepest, self::levelsUnder($entry));
            }
        }

        return $deepest;
    }

    private static function levelsUnder(Element $entry): int
    {
        $link = self::firstChild($entry, 'a');
        $button = self::firstChild($entry, 'button');
        if (!$link instanceof Element || !$button instanceof Element) {
            return 0;
        }

        $href = (string) $link->getAttribute('href');
        if ($href === '' || $href === '#') {
            return 0;
        }

        $opened = $entry->ownerDocument?->getElementById((string) $button->getAttribute('aria-controls'));
        if (!$opened instanceof Element || $opened->parentElement !== $entry) {
            return 0;
        }

        $deepest = 0;
        foreach ($opened->children as $child) {
            if ($child->localName === 'li') {
                $deepest = max($deepest, self::levelsUnder($child));
            }
        }

        return 1 + $deepest;
    }

    private static function firstChild(Element $parent, string $name): ?Element
    {
        foreach ($parent->children as $child) {
            if ($child->localName === $name) {
                return $child;
            }
        }

        return null;
    }

    private function tree(string $parentHref, string $childHref): string
    {
        return <<<HTML
            <ul>
                <li>
                    <a href="{$parentHref}">Collections</a>
                    <button type="button" aria-controls="collections-children" aria-expanded="false">Open</button>
                    <ul id="collections-children">
                        <li>
                            <a href="{$childHref}">Fossils</a>
                            <button type="button" aria-controls="fossils-children" aria-expanded="false">Open</button>
                            <ul id="fossils-children"><li><a href="/trilobites">Trilobites</a></li></ul>
                        </li>
                    </ul>
                </li>
            </ul>
            HTML;
    }

    private function specimen(string $markup): Element
    {
        $page = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body><div id="specimen">' . $markup . '</div></body></html>',
            LIBXML_NOERROR,
        );

        $specimen = $page->getElementById('specimen');
        self::assertInstanceOf(Element::class, $specimen);

        return $specimen;
    }
}
