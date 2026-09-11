<?php

declare(strict_types=1);

namespace Trilobit\Tests\Tooling;

use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Runner\KeepingUnitTestsAwayFromTheDatabase;

/**
 * The guard on the two doors into a database, tried from both sides.
 *
 * It stands in for a unit test rather than being one, because a unit test that
 * really reached for a database would be a failing test in the suite, and a
 * test whose whole job is to fail is a test somebody eventually marks skipped.
 * What is real here is everything else: the same guard object the runner drives
 * is told the same thing the runner tells it, and the doors it is asked about
 * are the ones the suite uses.
 *
 * **The refusal has to name the test.** "Something reached for a database" is
 * not a report anybody can act on in a run of eight hundred, which is why the
 * name is asserted and not just the exception.
 *
 * Exit condition: the pretended state is put back in a finally, and the runner
 * clears it again when this test finishes - so a mistake here cannot leak into
 * the next test in either direction.
 */
#[CoversNothing]
final class UnitTestsAreTurnedAwayFromTheDatabaseTest extends TestCase
{
    private const string PRETEND_TEST = 'Trilobit\Tests\Unit\Core\Example::testSomethingSmall';

    private const string PRETEND_FILE = '/app/tests/Unit/Core/ExampleTest.php';

    public function testASchemaIsRefusedToAUnitTestByName(): void
    {
        $this->pretendingToBeAUnitTest(function (): void {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage(self::PRETEND_TEST);

            Database::schemaFor(self::class);
        });
    }

    /**
     * The door that would otherwise open quietly: a container is built without
     * touching the server, and the connection it carries is aimed at whatever
     * database the machine is set up for.
     */
    public function testAContainerIsRefusedToAUnitTestByName(): void
    {
        $this->pretendingToBeAUnitTest(function (): void {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage(self::PRETEND_TEST);

            Boot::coreAlone();
        });
    }

    /**
     * The other half of the claim, and the one that keeps the guard from being
     * a guard against everything: a test that is not a unit test walks through.
     * Asserted on the file rather than on the class name, because that is what
     * the guard reads.
     */
    public function testATestOutsideTheUnitDirectoryIsNotRefused(): void
    {
        self::expectNotToPerformAssertions();

        KeepingUnitTestsAwayFromTheDatabase::nowRunning(self::class . '::testSomething', __FILE__);

        try {
            KeepingUnitTestsAwayFromTheDatabase::refuse('a database of its own');
        } finally {
            KeepingUnitTestsAwayFromTheDatabase::nothingIsRunning();
        }
    }

    /**
     * Nothing is running between one test and the next, and the guard has to
     * say so rather than keep blaming whoever ran last - a data provider
     * resolved between tests would otherwise be refused for somebody else's
     * sins.
     */
    public function testNothingIsRefusedBetweenTests(): void
    {
        self::expectNotToPerformAssertions();

        KeepingUnitTestsAwayFromTheDatabase::nowRunning(self::PRETEND_TEST, self::PRETEND_FILE);
        KeepingUnitTestsAwayFromTheDatabase::nothingIsRunning();

        KeepingUnitTestsAwayFromTheDatabase::refuse('a database of its own');
    }

    private function pretendingToBeAUnitTest(callable $body): void
    {
        KeepingUnitTestsAwayFromTheDatabase::nowRunning(self::PRETEND_TEST, self::PRETEND_FILE);

        try {
            $body();
        } finally {
            KeepingUnitTestsAwayFromTheDatabase::nothingIsRunning();
        }
    }
}
