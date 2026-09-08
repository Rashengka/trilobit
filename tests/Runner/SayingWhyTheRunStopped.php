<?php

declare(strict_types=1);

namespace Trilobit\Tests\Runner;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Makes a run that ended without finishing say so, and say why.
 *
 * A suite that runs out of memory dies on a fatal error, and a fatal error is
 * the one failure PHPUnit cannot turn into a report: the run stops between one
 * line of output and the next. What a reader is left with is a transcript that
 * differs from a green one by a missing last paragraph - the shape this project
 * calls a silent failure, because the observable output of dying is the output
 * of not having finished yet.
 *
 * PHPUnit covers half of it already: when the process dies inside a test it
 * prints which test that was and leaves with 2. It does not say why - "out of
 * memory" and "segmentation fault" read the same - and it says nothing at all
 * when the death happens between tests, in a data provider or in the reporting
 * afterwards, which leaves 255 and an empty screen.
 *
 * So this is the other half, and it is a mechanism rather than a note in a
 * README: a shutdown handler that knows whether the run reached its end and
 * prints, when it did not, PHP's own last error and what the memory limit was
 * against what was used. It is registered before PHPUnit's own handler, so the
 * two read as reason first and then which test.
 *
 * Exit condition: it prints nothing on a run that reached its end, so there is
 * no case in which it has to be switched off. It does not set the exit status -
 * PHP's 255 and PHPUnit's 2 are both already unmistakable, and it is the
 * silence that had to be fixed, not the number.
 */
final class SayingWhyTheRunStopped implements Extension
{
    private static bool $reachedTheEnd = false;

    private static string $lastTest = '';

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements PreparationStartedSubscriber {
            public function notify(PreparationStarted $event): void
            {
                SayingWhyTheRunStopped::remember($event->test()->id());
            }
        });

        $facade->registerSubscriber(new class implements ExecutionFinishedSubscriber {
            public function notify(ExecutionFinished $event): void
            {
                SayingWhyTheRunStopped::reachedTheEnd();
            }
        });

        register_shutdown_function(static function (): void {
            self::report();
        });
    }

    /** @internal called from the subscriber above, which cannot hold state of its own */
    public static function remember(string $test): void
    {
        self::$lastTest = $test;
    }

    /** @internal called from the subscriber above, which cannot hold state of its own */
    public static function reachedTheEnd(): void
    {
        self::$reachedTheEnd = true;
    }

    /**
     * Written to STDERR rather than through PHPUnit, which by this point has
     * either finished with its printer or never got to build one.
     */
    private static function report(): void
    {
        if (self::$reachedTheEnd) {
            return;
        }

        $error = error_get_last();

        fwrite(STDERR, PHP_EOL . implode(PHP_EOL, [
            'The run stopped before the end of the suite, so no summary below this line is a result.',
            sprintf('  last test started: %s', self::$lastTest === '' ? 'none - it stopped before the first test' : self::$lastTest),
            sprintf(
                '  PHP\'s last error: %s',
                $error === null ? 'none recorded' : sprintf('%s in %s on line %d', $error['message'], $error['file'], $error['line']),
            ),
            sprintf(
                '  memory:           the limit was %s, and the most PHP had taken from the system was %.1f MB',
                ini_get('memory_limit'),
                memory_get_peak_usage(true) / 1024 / 1024,
            ),
        ]) . PHP_EOL);
    }
}
