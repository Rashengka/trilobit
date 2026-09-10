<?php

declare(strict_types=1);

namespace Trilobit\Tests\Runner;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Trilobit\Tests\Boot;

/**
 * Gives the database server back what a finished test was holding.
 *
 * Every build a test compiles carries a connection, and a connection lives as
 * long as the process does. The process here is the whole run, so without this
 * the suite climbs towards the server's max_connections and then falls over it
 * - and falls over it quietly, because a suite that cannot connect reports
 * skipped tests and prints a green summary. Trilobit\Tests\Database now raises
 * on that rather than skipping, which makes the fall audible; this is what
 * stops the fall happening.
 *
 * **Registered in phpunit.xml rather than called from a tearDown**, because a
 * tearDown is a thing to remember and this must not be one. The rule holds for
 * every case in every suite, including the ones written after this file was
 * last read, and the only way to say that is to put it where the runner - not
 * the author of a test - is the one running it.
 *
 * Exit condition: nothing. There is no state to unwind and no case in which
 * skipping it is right; a build that needs its connection asks for it again and
 * gets a new one.
 */
final class ClosingConnections implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements FinishedSubscriber {
            public function notify(Finished $event): void
            {
                Boot::letGoOfEveryConnection();
            }
        });
    }
}
