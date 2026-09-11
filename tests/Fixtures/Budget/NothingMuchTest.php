<?php

declare(strict_types=1);

namespace Trilobit\Tests\Fixtures\Budget;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Tests\Runner\WhatTheWholeRunCost;

/**
 * A test that passes and spends a known slice of processor time doing it, so
 * that a run made of it crosses a tiny budget on every machine.
 *
 * The slice is measured with the same clock the budget is held against rather
 * than counted in iterations, because iterations are a guess about how fast the
 * machine is and this has to be certain on the slowest and the fastest alike.
 * The wall-clock stop beside it is there so that a broken clock ends this in a
 * failed expectation rather than in a loop nobody can see the end of.
 *
 * It is in no suite phpunit.xml declares. The only thing that ever runs it is
 * tests/Fixtures/over-budget.xml, from
 * Trilobit\Tests\Tooling\TheRunSaysWhatItCostTest.
 */
#[CoversNothing]
final class NothingMuchTest extends TestCase
{
    /** Five times the budget in tests/Fixtures/over-budget.xml. */
    private const float PROCESSOR_SECONDS_TO_SPEND = 0.25;

    /** However fast or slow the machine, this run gives up long before a person would. */
    private const float SECONDS_BEFORE_GIVING_UP = 10.0;

    public function testItPassesAndCostsSomething(): void
    {
        $spendUntil = WhatTheWholeRunCost::processorSecondsSoFar() + self::PROCESSOR_SECONDS_TO_SPEND;
        $giveUpAt = microtime(true) + self::SECONDS_BEFORE_GIVING_UP;
        $sum = 0.0;

        while (WhatTheWholeRunCost::processorSecondsSoFar() < $spendUntil && microtime(true) < $giveUpAt) {
            for ($i = 0; $i < 100_000; $i++) {
                $sum += sqrt($i);
            }
        }

        self::assertGreaterThan(0.0, $sum);
    }
}
