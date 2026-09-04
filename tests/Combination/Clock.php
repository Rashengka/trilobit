<?php

declare(strict_types=1);

namespace Trilobit\Tests\Combination;

/**
 * How long the combination suite took, carried from the class that does the
 * work to the class that holds it to its budget.
 *
 * A budget nobody measures is a wish. This is the smallest thing that turns it
 * into a number: the suite records its own wall time, and BudgetTest asserts
 * on it. Nothing else may write here.
 */
final class Clock
{
    private static ?float $elapsed = null;

    public static function record(float $seconds): void
    {
        self::$elapsed = (self::$elapsed ?? 0.0) + $seconds;
    }

    /** Null when nothing has been measured in this process. */
    public static function elapsed(): ?float
    {
        return self::$elapsed;
    }
}
