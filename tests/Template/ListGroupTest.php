<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\ListGroupItem;

/**
 * c-list-group as markup: a list of entries, each of them either a line or the
 * way somewhere, one of them possibly the current one, some carrying a count.
 */
#[CoversClass(ListGroupItem::class)]
final class ListGroupTest extends TestCase
{
    public function testItIsAListOfItsEntries(): void
    {
        $page = $this->render([new ListGroupItem('Drafts'), new ListGroupItem('Published')]);

        self::assertSame('ul', $this->list($page)->localName);
        self::assertSame(['Drafts', 'Published'], $this->texts($page->querySelectorAll('.c-list-group > li')));
    }

    /** An entry with no address is a line, not a link that goes nowhere. */
    public function testAnEntryWithoutAnAddressIsNotALink(): void
    {
        self::assertNull($this->list($this->render([new ListGroupItem('Drafts')]))->querySelector('a'));
    }

    public function testAnEntryWithAnAddressIsALink(): void
    {
        $link = $this->list($this->render([new ListGroupItem('Drafts', '/drafts', testId: 'drafts')]))->querySelector('a');

        self::assertNotNull($link);
        self::assertSame('/drafts', $link->getAttribute('href'));
        self::assertSame('drafts', $link->getAttribute('data-testid'));
    }

    /**
     * The current entry of a list of links is the page you are on; the current
     * entry of a list of lines is only the one that is on, and says so without
     * claiming to be a page.
     */
    public function testTheCurrentEntryIsMarkedForWhatItIs(): void
    {
        $list = $this->list($this->render([
            new ListGroupItem('Drafts', '/drafts', current: true),
            new ListGroupItem('Published', '/published'),
        ]));
        $current = $list->querySelectorAll('[aria-current]');
        self::assertCount(1, $current);
        $entry = $current->item(0);
        self::assertInstanceOf(Element::class, $entry);
        self::assertSame('page', $entry->getAttribute('aria-current'));
        self::assertSame('a', $entry->localName);

        $lines = $this->list($this->render([new ListGroupItem('Drafts', current: true)]));
        self::assertSame('true', $lines->querySelector('[aria-current]')?->getAttribute('aria-current'));
    }

    public function testACountIsABadgeInsideTheEntry(): void
    {
        $link = $this->list($this->render([new ListGroupItem('Drafts', '/drafts', badge: '4')]))->querySelector('a');

        self::assertNotNull($link);
        $badge = $link->querySelector('.c-badge');
        self::assertNotNull($badge, 'the count is outside the link, so the link is not named with it');
        self::assertSame('4', trim((string) $badge->textContent));
    }

    /** @param list<ListGroupItem> $items */
    private function render(array $items): HTMLDocument
    {
        return ComponentRendering::render('list-group.latte', "{include listGroup, items: \$items, testId: 'list'}", ['items' => $items]);
    }

    private function list(HTMLDocument $page): Element
    {
        $list = $page->querySelector('.c-list-group');
        self::assertNotNull($list, 'c-list-group drew nothing with its class');
        self::assertSame('list', $list->getAttribute('data-testid'));

        return $list;
    }

    /**
     * @param iterable<Element> $elements
     *
     * @return list<string>
     */
    private function texts(iterable $elements): array
    {
        $texts = [];
        foreach ($elements as $element) {
            $texts[] = trim((string) $element->textContent);
        }

        return $texts;
    }
}
