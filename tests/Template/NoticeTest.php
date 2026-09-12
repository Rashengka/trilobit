<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * c-notice with a title: a refusal or a piece of news that needs more than one
 * sentence - what happened, and then what to do about it.
 *
 * The title and the sentence are one live region, announced together, because
 * a title read out on its own says that something happened and not what. The
 * title is not a heading: the notice cannot know where in the outline of the
 * page it is drawn, and a heading at the wrong level is worse than none. How
 * dismissing a notice works is c-close's and tests/Template/CloseTest's.
 */
#[CoversNothing]
final class NoticeTest extends TestCase
{
    public function testTheTitleAndTheSentenceAreOneRegion(): void
    {
        $notice = $this->noticeIn($this->draw(
            "{include notice, title: 'The page was not published', message: 'Its address is taken by another page.', variant: 'danger'}",
        ));

        self::assertSame('alert', $notice->getAttribute('role'));
        self::assertTrue($notice->classList->contains('c-notice--titled'));
        self::assertTrue($notice->classList->contains('c-notice--danger'));
        self::assertSame('The page was not published', trim((string) $notice->querySelector('.c-notice__title')?->textContent));
        self::assertSame(
            'Its address is taken by another page.',
            trim((string) $notice->querySelector('.c-notice__message')?->textContent),
        );
        self::assertCount(1, $notice->ownerDocument?->querySelectorAll('[role]') ?? []);
    }

    public function testTheTitleIsNotAHeading(): void
    {
        $drawn = $this->draw("{include notice, title: 'Saved', message: 'The draft is kept for a week.'}");

        self::assertNull($drawn->querySelector('h1, h2, h3, h4, h5, h6'));
        self::assertSame('status', $this->noticeIn($drawn)->getAttribute('role'));
    }

    public function testWithoutATitleItIsTheSentenceItAlwaysWas(): void
    {
        $notice = $this->noticeIn($this->draw("{include notice, message: 'The catalogue was saved.'}"));

        self::assertSame('p', $notice->localName);
        self::assertSame('c-notice', $notice->getAttribute('class'));
    }

    /**
     * A dismissible notice keeps its live region on the sentence alone, so
     * that the button is not read out with it; a title would have to join that
     * region, which is a change to how dismissing works. Refused until a page
     * needs both.
     */
    public function testATitleOnADismissibleNoticeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->draw("{include notice, title: 'Saved', message: 'The draft is kept.', dismissible: true}");
    }

    private function draw(string $call): HTMLDocument
    {
        return ComponentRendering::render('notice.latte', $call);
    }

    private function noticeIn(HTMLDocument $drawn): Element
    {
        $notice = $drawn->querySelector('.c-notice');
        self::assertInstanceOf(Element::class, $notice, 'c-notice drew no .c-notice');

        return $notice;
    }
}
