<?php

declare(strict_types=1);

namespace Trilobit\Tests\Runner;

use LogicException;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Makes the difference between the two kinds of test a thing the runner
 * enforces rather than a thing an author remembers.
 *
 * The two kinds answer different questions and are allowed different worlds.
 * An integration test asks whether the application works against the server it
 * will really run on, so it needs one and fails without it - see
 * Trilobit\Tests\NoDatabaseToTestAgainst. A unit test asks whether one piece
 * behaves, on any machine, with nothing installed, which is only true while it
 * touches nothing outside the process: what it does not have, it stands in for.
 * That is why a unit test has no skip in it either. A skip would mean it had
 * grown a dependency on something that might not be there, and the answer to
 * that is a double, not a skip.
 *
 * **So the rule is enforced where the runner knows which test is speaking, not
 * where a test could choose to be careful.** A rule in a README is kept by
 * whoever has read it, which never includes the test somebody writes next
 * month; the same rule held by the two doors into a database is kept by
 * everybody. The doors are Trilobit\Tests\Database, which is the only way to a
 * schema, and Trilobit\Tests\Boot, which is the only way to a container that
 * carries a connection. Both ask here first, and both name the test in the
 * refusal, because "a unit test reached for a database" is useless without
 * which one.
 *
 * **A unit test is one whose file is under tests/Unit**, which is the same
 * sentence phpunit.xml uses to decide what the unit suite contains. Reading the
 * file rather than the class name means the two cannot come apart: a class
 * moved out of that directory stops being a unit test in both places at once.
 *
 * The lexical half of the same rule is
 * Trilobit\Tests\Architecture\NoUnitTestKnowsTheWayToADatabaseTest, which reads
 * the files instead of watching the run and so also catches a road that goes
 * around both doors. Neither is a substitute for the other: this one cannot see
 * a road it was not asked to guard, that one cannot see anything but a name.
 *
 * Exit condition: nothing to unwind. The window is one test wide - opened when
 * a test is prepared and closed when it finishes - so nothing running between
 * tests, a data provider included, is attributed to a test that is not running.
 */
final class KeepingUnitTestsAwayFromTheDatabase implements Extension
{
    /** The directory phpunit.xml gives to the unit suite, as it appears in a path. */
    private const string WHERE_UNIT_TESTS_LIVE = DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit' . DIRECTORY_SEPARATOR;

    /** The unit test running right now, or null when the test running is not one. */
    private static ?string $unitTestRunningNow = null;

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements PreparationStartedSubscriber {
            public function notify(PreparationStarted $event): void
            {
                KeepingUnitTestsAwayFromTheDatabase::nowRunning($event->test()->id(), $event->test()->file());
            }
        });

        $facade->registerSubscriber(new class implements FinishedSubscriber {
            public function notify(Finished $event): void
            {
                KeepingUnitTestsAwayFromTheDatabase::nothingIsRunning();
            }
        });
    }

    /** @internal called from the subscriber above, which cannot hold state of its own */
    public static function nowRunning(string $test, string $file): void
    {
        self::$unitTestRunningNow = str_contains($file, self::WHERE_UNIT_TESTS_LIVE) ? $test : null;
    }

    /** @internal called from the subscriber above, which cannot hold state of its own */
    public static function nothingIsRunning(): void
    {
        self::$unitTestRunningNow = null;
    }

    /**
     * Lets a unit test no further, and says which test and what it reached for.
     *
     * The refusal is a LogicException rather than a failed assertion on
     * purpose: a unit test asking for a database is not a claim that turned out
     * false, it is a test that has been written wrongly, and the two want
     * different reading. It is raised at the door rather than at the socket so
     * that it costs no wait and cannot be confused with the server being down.
     *
     * @throws LogicException when the test running now is a unit test
     */
    public static function refuse(string $wasAskedFor): void
    {
        if (self::$unitTestRunningNow === null) {
            return;
        }

        throw new LogicException(sprintf(
            '%s is a unit test and asked for %s. A unit test runs on any machine with nothing installed, '
            . 'which is only true while it reaches for nothing outside the process, so it stands in for what '
            . 'it does not have rather than connecting to it. A test that really needs the server belongs in '
            . 'tests/Integration, where a missing database is a failure and not a skip.',
            self::$unitTestRunningNow,
            $wasAskedFor,
        ));
    }
}
