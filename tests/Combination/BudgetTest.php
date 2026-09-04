<?php

declare(strict_types=1);

namespace Trilobit\Tests\Combination;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The budget for this suite is an assertion, not an agreement.
 *
 * Eight builds is eight container compilations, and the honest answer to "is
 * that still fast enough" is a number rather than an opinion. Once it is not,
 * the fix is to make the suite faster - running the combinations in parallel
 * is the step that was deliberately left until this line goes red. Raising the
 * budget is not one of the options.
 *
 * The file is named so that it sorts after the suite it measures, because
 * PHPUnit walks a directory in order. Run on its own it has nothing to read,
 * so it does the eight builds itself rather than passing on an empty
 * measurement.
 */
#[CoversNothing]
final class BudgetTest extends TestCase
{
    /** Seconds. From the test strategy: over this, the suite gets faster, not the budget bigger. */
    private const float Budget = 90.0;

    public function testTheWholeSuiteFitsInItsBudget(): void
    {
        $elapsed = Clock::elapsed() ?? $this->measureAPassOfItsOwn();

        // Straight to the error stream rather than through the output buffer,
        // because a suite that prints is a suite PHPUnit reports as risky.
        fwrite(STDERR, sprintf("\ncombination suite: %.1f s of a %.0f s budget\n", $elapsed, self::Budget));

        self::assertLessThanOrEqual(
            self::Budget,
            $elapsed,
            'the combination suite is over budget; make it faster rather than raising this number',
        );
    }

    private function measureAPassOfItsOwn(): float
    {
        $startedAt = microtime(true);

        foreach (Build::everyCombination() as [$enabled]) {
            Build::render(Build::container($enabled), 'Core:Front:Home');
        }

        return microtime(true) - $startedAt;
    }
}
