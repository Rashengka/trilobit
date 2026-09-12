<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\ComponentRegistry;
use Trilobit\Core\Presentation\Component\HeadingLevel;

/**
 * c-collapse is the browser's own disclosure, <details> and <summary>, and
 * nothing of the behaviour is ours: the name, the keyboard and the state of
 * being open are what the browser gives those two elements. What is ours is
 * the markup that lets it give them - the summary as the first child, one
 * heading at most and inside the summary, a name only when the caller shares
 * one - and that is what is held here. What the browser then does with it is
 * measured in one, in tests/e2e/disclosure.spec.ts.
 *
 * Children are looked up by walking them rather than with :scope, which the
 * DOM parser of PHP 8.4 - the floor of require.php - does not understand.
 */
#[CoversClass(HeadingLevel::class)]
final class CollapseTest extends TestCase
{
    public function testItIsADisclosureTheBrowserOpensAndItStartsClosed(): void
    {
        $details = $this->details($this->draw(
            "{embed block collapse, title: 'Care of the specimen'}{block collapseBody}<p>Dust it.</p>{/block}{/embed}",
        ));

        self::assertSame('DETAILS', $details->tagName);
        self::assertFalse($details->hasAttribute('open'), 'a collapse the caller did not open starts closed');

        $summary = $details->firstElementChild;
        self::assertNotNull($summary);
        self::assertSame(
            'SUMMARY',
            $summary->tagName,
            'the summary has to be the first child, or the browser draws one of its own',
        );
        self::assertSame('c-collapse__toggle', $summary->className);
        self::assertSame('Care of the specimen', trim($summary->textContent ?? ''));

        $body = $this->child($details, 'c-collapse__body');
        self::assertNotNull($body, 'what the collapse hides is in c-collapse__body, directly under the details');
        self::assertSame('Dust it.', trim($body->textContent ?? ''));
    }

    public function testItIsOpenWhenTheCallerSaysSo(): void
    {
        self::assertTrue($this->details($this->draw("{include collapse, title: 'Care', open: true}"))->hasAttribute('open'));
    }

    /**
     * The name is what makes a group of them open one at a time; a collapse
     * standing on its own must not carry one, or it would close whichever
     * other one on the page happened to share it.
     */
    public function testItCarriesANameAndAnAddressOnlyWhenGivenThem(): void
    {
        $plain = $this->details($this->draw("{include collapse, title: 'Care'}"));
        self::assertFalse($plain->hasAttribute('name'));
        self::assertFalse($plain->hasAttribute('id'));

        $named = $this->details($this->draw("{include collapse, title: 'Care', name: 'questions', id: 'care'}"));
        self::assertSame('questions', $named->getAttribute('name'));
        self::assertSame('care', $named->getAttribute('id'));
    }

    /** @return iterable<string, array{int}> */
    public static function everyLevel(): iterable
    {
        foreach (HeadingLevel::cases() as $level) {
            yield 'h' . $level->value => [$level->value];
        }
    }

    /**
     * Inside the summary, so that the outline of the page lists it and a screen
     * reader's list of headings reaches it; the class decides its size, so it
     * looks the same at every level.
     */
    #[DataProvider('everyLevel')]
    public function testWithALevelItsTitleIsAHeadingInsideTheSummary(int $level): void
    {
        $details = $this->details($this->draw(sprintf("{include collapse, title: 'Care', level: %d}", $level)));

        $title = $this->titleOf($details);
        self::assertSame('H' . $level, $title->tagName);
        self::assertSame('Care', trim($title->textContent ?? ''));
    }

    public function testWithoutALevelItsTitleIsNoHeading(): void
    {
        $details = $this->details($this->draw("{include collapse, title: 'Care'}"));

        self::assertNull($details->querySelector('h1, h2, h3, h4, h5, h6'));
        self::assertSame('SPAN', $this->titleOf($details)->tagName);
    }

    /**
     * The first level is the page's own title (c-page-heading), and there is
     * no seventh: either would be a heading drawn as something else, or not at
     * all, with nothing to say so.
     */
    public function testALevelThatIsNoHeadingOfAPartIsRefused(): void
    {
        foreach ([1, 7] as $level) {
            try {
                $this->draw(sprintf("{include collapse, title: 'Care', level: %d}", $level));
                self::fail(sprintf('level %d was drawn', $level));
            } catch (\ValueError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** The mark that it opens says nothing a screen reader has not been told by the state. */
    public function testItsMarkIsSilent(): void
    {
        $icon = $this->summaryOf($this->details($this->draw("{include collapse, title: 'Care'}")))->querySelector('svg.c-icon');

        self::assertNotNull($icon);
        self::assertSame('true', $icon->getAttribute('aria-hidden'));
    }

    public function testTheStyleGuideShowsEveryVariant(): void
    {
        self::assertSame(
            ['default', 'open from the start', 'with a heading'],
            new ComponentRegistry()->find('c-collapse')?->variants,
        );
    }

    private function draw(string $call): HTMLDocument
    {
        return ComponentRendering::render('collapse.latte', $call);
    }

    private function details(HTMLDocument $drawn): Element
    {
        $details = $drawn->querySelector('.c-collapse');
        self::assertNotNull($details, 'c-collapse drew nothing carrying .c-collapse');

        return $details;
    }

    private function summaryOf(Element $details): Element
    {
        $summary = $details->firstElementChild;
        self::assertNotNull($summary);
        self::assertSame('SUMMARY', $summary->tagName);

        return $summary;
    }

    /** The title, which has to be a child of the summary and not merely somewhere in it. */
    private function titleOf(Element $details): Element
    {
        $title = $this->child($this->summaryOf($details), 'c-collapse__title');
        self::assertNotNull($title, 'the summary has no c-collapse__title among its children');

        return $title;
    }

    private function child(Element $parent, string $class): ?Element
    {
        for ($child = $parent->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            if ($child->classList->contains($class)) {
                return $child;
            }
        }

        return null;
    }
}
