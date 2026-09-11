<?php

declare(strict_types=1);

namespace Trilobit\Tests\Runner;

use PHPUnit\Event\Facade as Events;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use RuntimeException;

/**
 * Makes the whole run say what it cost, and hold that against a number it
 * names.
 *
 * Trilobit\Tests\Combination\BudgetTest has done this for its own eight builds
 * since they were written, and the argument is the same one storey up: a suite
 * gets slower the way anything gets slower, a little at a time, and nobody
 * notices a little at a time. Composer's process-timeout is not this. It is a
 * watchdog set five times higher than the longest step ever measured, and its
 * job is to stop a run that will never finish; it says nothing about a run that
 * finishes and took twice as long as it used to.
 *
 * **The budget is held against processor time and not against the clock, and
 * that is how the awkward question was settled.** The awkward question is what
 * a budget should do when it is crossed by a machine that was busy. A gate that
 * goes red for a reason that is not in the code teaches people to run it again,
 * and a gate people run again is a gate they no longer read - so a clock-time
 * budget is either loose enough to sit above whatever a loaded afternoon
 * produces, in which case it would not notice the suite doubling, or it is a
 * gate nobody believes.
 *
 * The reason for choosing processor time is measured and it is narrower than
 * the tidy version of the argument. Three runs of this suite over the same
 * tree: quiet, 58.2 s of processor time and 237 s on the clock; quiet again,
 * 63.7 s and 335 s; and then against sixteen processes spinning on a fourteen
 * core machine, 130.8 s and 697 s. So processor time is **not** untouched by a
 * busy machine - contention costs it too - but the clock stretched by 2.9 times
 * where the processor stretched by 2.2. Buying the same protection from false
 * alarms therefore costs a good deal less headroom on this measure than on the
 * other, and headroom is exactly what a budget spends its usefulness on.
 *
 * **Over budget is therefore a failure and not a note.** This project has spent
 * this whole slice removing guards that printed nothing, and a warning nobody
 * has to act on is one of them: it goes into the scroll-back with the other
 * three hundred lines. The argument for reporting instead was that the number
 * cannot be trusted, and it is answered by buying the headroom rather than by
 * softening the verdict - a budget that is honestly loose and hard is worth
 * more than one that is tight and advisory, because the loose one still fires
 * on the day it matters and somebody still has to do something. When it does
 * fire, the fix is to make the suite faster - the combinations still run one
 * after another, and running them in parallel is the step deliberately left
 * until then. Raising the number is not one of the options.
 *
 * **It prints on a green run too**, which is the half that makes it worth
 * having. A budget that only speaks when it has been crossed is one nobody
 * watches approaching, and approaching is when a suite is still cheap to make
 * faster.
 *
 * The clock and the peak memory are printed beside the processor time and
 * nothing is asserted about them. They are the two numbers a reader wants when
 * the third one moves - a run whose processor time held and whose clock doubled
 * is a machine that was busy, and one where both moved is the suite.
 *
 * **What this cannot see, said out loud.** Measured on this build the suite
 * spends about a quarter of its wall time computing and the rest waiting, most
 * of it on MariaDB in the other container. Waiting is what processor time does
 * not count, which is the property being bought - and it is also the blind
 * spot, because a change that made the suite slower purely by asking the
 * database more often would show on the clock and barely here. Nothing pretends
 * otherwise: the clock is printed on every run so that the two can be read
 * against each other, and a reader who sees the clock climb while this holds
 * knows exactly which of the two happened. **Exit condition:** a second budget,
 * over the clock and set high enough that only a real problem reaches it,
 * becomes worth its false alarms the first time a database-bound regression
 * gets past this one.
 *
 * Exit condition: none. A run that is inside its budget prints one line and
 * changes nothing else.
 */
final class WhatTheWholeRunCost implements Extension
{
    /**
     * The name phpunit.xml gives the budget, in processor seconds.
     *
     * It lives in the configuration rather than in a constant here so that the
     * number and the suites it covers are read in one place, and so that a run
     * against another configuration - the one this suite's own test uses to
     * prove the failure - can name a different one without touching this class.
     * There is no default: a missing budget is reported as an error, because a
     * budget that quietly disappears when somebody tidies an XML file is a
     * budget that was never enforced.
     */
    private const string BUDGET_PARAMETER = 'processorSecondsBudget';

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $budget = $parameters->has(self::BUDGET_PARAMETER) ? $parameters->get(self::BUDGET_PARAMETER) : null;
        $startedAt = microtime(true);
        $processorSecondsAtStart = self::processorSecondsSoFar();

        $facade->registerSubscriber(new readonly class ($budget, $startedAt, $processorSecondsAtStart) implements ExecutionFinishedSubscriber {
            public function __construct(
                private ?string $budget,
                private float $startedAt,
                private float $processorSecondsAtStart,
            ) {}

            public function notify(ExecutionFinished $event): void
            {
                WhatTheWholeRunCost::report(
                    $this->budget,
                    microtime(true) - $this->startedAt,
                    WhatTheWholeRunCost::processorSecondsSoFar() - $this->processorSecondsAtStart,
                );
            }
        });
    }

    /**
     * @internal called from the subscriber above, which cannot hold state of
     *     its own
     */
    public static function report(?string $budget, float $onTheClock, float $processorSeconds): void
    {
        if ($budget === null || !is_numeric($budget) || (float) $budget <= 0.0) {
            self::raise(sprintf(
                'The run has no budget: phpunit.xml gives %s no "%s" parameter, or gives it something that is '
                . 'not a number of seconds above zero, so nothing held this run to a cost. It took %.1f s of '
                . 'processor time and %.1f s on the clock.',
                self::class,
                self::BUDGET_PARAMETER,
                $processorSeconds,
                $onTheClock,
            ));

            return;
        }

        $peakInMegabytes = memory_get_peak_usage(true) / 1024 / 1024;

        // Straight to the error stream rather than through the output buffer,
        // for the reason BudgetTest gives: a suite that prints is a suite
        // PHPUnit reports as risky.
        fwrite(STDERR, sprintf(
            "\nwhole run: %.1f s of processor time of a %s s budget (%.1f s on the clock, %.1f MB peak)\n",
            $processorSeconds,
            // %g rather than a fixed number of places, so that the everyday
            // budget prints as "300" and the hundredth of a second the fixture
            // configuration uses does not print as "0".
            sprintf('%g', (float) $budget),
            $onTheClock,
            $peakInMegabytes,
        ));

        $verdict = self::verdict($processorSeconds, (float) $budget);
        if ($verdict !== null) {
            self::raise($verdict);
        }
    }

    /**
     * The complaint, or null when the run was inside its budget.
     *
     * Separated from the reporting so that the one decision this class makes
     * can be asserted on directly, without a run to make it about - see
     * Trilobit\Tests\Tooling\TheRunSaysWhatItCostTest.
     *
     * Equal to the budget passes. A budget is a ceiling somebody may stand on,
     * and a comparison that failed on the exact number would make the printed
     * figure disagree with the verdict at one value.
     *
     * @return non-empty-string|null
     */
    public static function verdict(float $processorSeconds, float $budget): ?string
    {
        if ($processorSeconds <= $budget) {
            return null;
        }

        return sprintf(
            'The suite is over its budget: it took %.1f s of processor time against a budget of %s s. '
            . 'Processor time is held to rather than the clock precisely so that a busy machine cannot be '
            . 'what did this, so the suite really has got slower. Make it faster - the module combinations '
            . 'still run one after another - rather than raising the budget in phpunit.xml.',
            $processorSeconds,
            sprintf('%g', $budget),
        );
    }

    /**
     * The processor seconds this run has spent, its own and those of every
     * process it started and waited for.
     *
     * The children matter and are easy to leave out: the suite shells out to
     * PHP for the leak guard and to the migration tools, and work done there is
     * work the suite caused. User and system time are added together for the
     * same reason - a suite that got slower by making more system calls got
     * slower.
     */
    public static function processorSecondsSoFar(): float
    {
        return self::secondsIn(getrusage(), 'this process') + self::secondsIn(getrusage(1), 'the processes it started');
    }

    /**
     * Raised rather than counted as nothing when the platform will not say.
     *
     * getrusage() answers false where it is not supported, and a measurement
     * that quietly becomes zero is a budget that can never fire: every run
     * would print "0.0 s" and pass, which is the exact shape of failure the
     * rest of this class exists to remove. A platform that cannot be measured
     * has to say so once, loudly, rather than agree with everything for ever.
     *
     * @param array<array-key, mixed>|false $usage
     */
    private static function secondsIn(array|false $usage, string $whose): float
    {
        if ($usage === false) {
            throw new RuntimeException(sprintf(
                'getrusage() would not say how much processor time %s has used, so the run cannot be held to '
                . 'a budget on this platform. Nothing here can be measured instead: a budget fed a zero '
                . 'passes every time and would say so in the same words as a run that was genuinely fast.',
                $whose,
            ));
        }

        // Time spent in the program and time spent in the kernel on its
        // behalf, because a suite that got slower by making more system calls
        // got slower.
        $parts = [
            'ru_utime.tv_sec' => 1.0,
            'ru_utime.tv_usec' => 0.000_001,
            'ru_stime.tv_sec' => 1.0,
            'ru_stime.tv_usec' => 0.000_001,
        ];

        $seconds = 0.0;
        foreach ($parts as $name => $scale) {
            $part = $usage[$name] ?? null;
            if (!is_numeric($part)) {
                throw new RuntimeException(sprintf(
                    'getrusage() gave no number for "%s" of %s, so the processor time of this run is not '
                    . 'known. It is raised rather than left out of the sum, because a sum missing one of its '
                    . 'four parts is a smaller number that looks exactly like a faster suite.',
                    $name,
                    $whose,
                ));
            }

            $seconds += (float) $part * $scale;
        }

        return $seconds;
    }

    /**
     * Reported as an error of the run rather than as a failed test, because it
     * is not a claim any one test makes. PHPUnit counts it among the errors,
     * prints it under the summary and leaves with a non-zero status, which is
     * the whole point: a budget that printed a warning would be one more line
     * in a scroll-back nobody reads to the end.
     *
     * @param non-empty-string $message
     */
    private static function raise(string $message): void
    {
        Events::emitter()->testRunnerTriggeredError($message, __FILE__, __LINE__, false);
    }
}
