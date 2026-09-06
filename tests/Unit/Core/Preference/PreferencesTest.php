<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Preference;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Preference\PreferenceCatalogue;
use Trilobit\Core\Preference\Preferences;
use Trilobit\Core\Presentation\Design\DesignSystem;

/**
 * Who decides how the application is drawn, when the person reading it and the
 * page they are on disagree.
 *
 * The ordering is the one that makes the person's setting the ordinary case:
 * what somebody chose is what they get, and a page overrules it only where it
 * has a hard reason - a report with a column for every day of a month cannot
 * wait for somebody to remember to switch (see
 * .ai/plans/09-chrome-a-sirka-obsahu.md, L4).
 *
 * The half that is easy to get wrong is not who wins but what is written down.
 * A page overruling a width must not turn into the person having chosen it,
 * because the next page they open would then be drawn that way for good - so
 * chosen() is asserted below beside every claim about value().
 */
#[CoversClass(Preferences::class)]
#[CoversClass(PreferenceCatalogue::class)]
final class PreferencesTest extends TestCase
{
    public function testAPageThatOverrulesNothingDrawsWhatSomebodyChose(): void
    {
        $preferences = $this->chosen([PreferenceCatalogue::CONTENT_WIDTH => 'wide']);

        self::assertSame('wide', $preferences->value(PreferenceCatalogue::CONTENT_WIDTH));
        self::assertSame(
            'wide',
            $preferences->attributes()['data-content-width'] ?? null,
            'the page is drawn out of the attributes, so this is what the browser really sees',
        );
        self::assertFalse($preferences->isOverruled(PreferenceCatalogue::CONTENT_WIDTH));
    }

    public function testAPageThatOverrulesAWidthWinsOverTheChoice(): void
    {
        $preferences = $this
            ->chosen([PreferenceCatalogue::CONTENT_WIDTH => 'content'])
            ->overruledWith(PreferenceCatalogue::CONTENT_WIDTH, 'full');

        self::assertSame('full', $preferences->value(PreferenceCatalogue::CONTENT_WIDTH));
        self::assertSame('full', $preferences->attributes()['data-content-width'] ?? null);
        self::assertTrue($preferences->isOverruled(PreferenceCatalogue::CONTENT_WIDTH));
    }

    /**
     * A page insisting on a width is a fact about that page and about nothing
     * else. If it reached chosen() it would be written to the cookie and to the
     * profile at the next opportunity, and the person would come away from one
     * report with every other page changed.
     */
    public function testWhatThePageOverrulesIsNotSomethingSomebodyChose(): void
    {
        $preferences = $this
            ->chosen([PreferenceCatalogue::CONTENT_WIDTH => 'content'])
            ->overruledWith(PreferenceCatalogue::CONTENT_WIDTH, 'full');

        self::assertSame([PreferenceCatalogue::CONTENT_WIDTH => 'content'], $preferences->chosen());
        self::assertSame(
            'content',
            $preferences->preferred(PreferenceCatalogue::CONTENT_WIDTH),
            'a control has to go on showing the setting, not the width this one page is drawn at',
        );
    }

    public function testOverrulingOnePreferenceLeavesTheOthersAlone(): void
    {
        $preferences = $this
            ->chosen([PreferenceCatalogue::THEME => 'ledger', PreferenceCatalogue::CONTENT_WIDTH => 'content'])
            ->overruledWith(PreferenceCatalogue::CONTENT_WIDTH, 'full');

        self::assertSame('ledger', $preferences->value(PreferenceCatalogue::THEME));
        self::assertFalse($preferences->isOverruled(PreferenceCatalogue::THEME));
    }

    /**
     * Somebody who has chosen nothing is drawn with the build's own answer, and
     * a page may overrule that as readily as it overrules a choice.
     */
    public function testAPageOverrulesTheBuildsOwnAnswerToo(): void
    {
        $preferences = $this->chosen([]);
        self::assertSame('content', $preferences->value(PreferenceCatalogue::CONTENT_WIDTH));

        self::assertSame(
            'full',
            $preferences->overruledWith(PreferenceCatalogue::CONTENT_WIDTH, 'full')
                ->value(PreferenceCatalogue::CONTENT_WIDTH),
        );
    }

    /**
     * A stored value this build no longer has is dropped without a word, because
     * it is a cookie somebody's browser has been carrying since before the theme
     * was renamed. A page asking for a width nobody wrote is the opposite: it is
     * a line of code in this checkout, so it has to be loud.
     */
    public function testAPageAskingForAWidthThisBuildDoesNotHaveIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->chosen([])->overruledWith(PreferenceCatalogue::CONTENT_WIDTH, 'as-wide-as-possible');
    }

    public function testAPageAskingForAPreferenceThisBuildDoesNotHaveIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->chosen([])->overruledWith('column-count', 'three');
    }

    /** @param array<string, string> $chosen */
    private function chosen(array $chosen): Preferences
    {
        return PreferenceCatalogue::of(
            DesignSystem::of(Bootstrap::rootDirectory(), 'atrium'),
        )->reconcile($chosen);
    }
}
