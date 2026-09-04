<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Config\Environment;

#[CoversClass(Environment::class)]
final class EnvironmentTest extends TestCase
{
    public function testReadsAssignments(): void
    {
        $environment = Environment::fromString("TRILOBIT_DB_HOST=db.example.com\nTRILOBIT_DB_PORT=3306\n");

        self::assertSame('db.example.com', $environment->get('TRILOBIT_DB_HOST'));
        self::assertSame('3306', $environment->get('TRILOBIT_DB_PORT'));
    }

    public function testSkipsCommentsBlankLinesAndLinesWithoutAnAssignment(): void
    {
        $environment = Environment::fromString("# a comment\n\nNOT_AN_ASSIGNMENT\nKEPT=yes\n");

        self::assertSame(['KEPT' => 'yes'], $environment->all());
    }

    public function testTrimsAroundTheNameAndTheValue(): void
    {
        $environment = Environment::fromString("  SPACED  =  value  \n");

        self::assertSame('value', $environment->get('SPACED'));
    }

    public function testStripsOneLayerOfMatchingQuotes(): void
    {
        $environment = Environment::fromString("DOUBLE=\"a b\"\nSINGLE='c d'\nMIXED=\"e'\n");

        self::assertSame('a b', $environment->get('DOUBLE'));
        self::assertSame('c d', $environment->get('SINGLE'));
        self::assertSame('"e\'', $environment->get('MIXED'));
    }

    public function testKeepsAnEmptyValueAsAnEmptyString(): void
    {
        $environment = Environment::fromString("TRILOBIT_DEBUG=\n");

        self::assertSame('', $environment->get('TRILOBIT_DEBUG'));
    }

    public function testReportsAnAbsentNameAsNull(): void
    {
        self::assertNull(Environment::fromString('')->get('ABSENT'));
    }

    public function testTheProcessEnvironmentWinsOverTheFile(): void
    {
        $environment = Environment::fromValues(['SHARED' => 'from file'], ['SHARED' => 'from process']);

        self::assertSame('from process', $environment->get('SHARED'));
    }

    public function testAFlagIsOnForAnyValueButTheEmptyOne(): void
    {
        $environment = Environment::fromString("ON=1\nALSO_ON=anything\nOFF=\n");

        self::assertTrue($environment->flag('ON'));
        self::assertTrue($environment->flag('ALSO_ON'));
        self::assertFalse($environment->flag('OFF'));
        self::assertFalse($environment->flag('ABSENT'));
    }

    public function testTheResolvedValuesTakeTheProcessOverTheFile(): void
    {
        $environment = Environment::fromValues(
            ['TRILOBIT_DB_HOST' => 'from file', 'TRILOBIT_DB_NAME' => 'untouched'],
            ['TRILOBIT_DB_HOST' => 'from process'],
        );

        self::assertSame(
            ['TRILOBIT_DB_HOST' => 'from process', 'TRILOBIT_DB_NAME' => 'untouched'],
            $environment->resolved(),
        );
    }

    public function testTheResolvedValuesAcceptAPrefixedNameTheFileNeverMentioned(): void
    {
        $environment = Environment::fromValues([], ['TRILOBIT_DEBUG' => '1']);

        self::assertSame(['TRILOBIT_DEBUG' => '1'], $environment->resolved());
    }

    public function testTheResolvedValuesLeaveTheRestOfTheMachineOut(): void
    {
        $environment = Environment::fromValues([], ['PATH' => '/somewhere', 'LANG' => 'en_US.UTF-8']);

        self::assertSame([], $environment->resolved());
    }

    public function testAMissingFileIsNotAnError(): void
    {
        $environment = Environment::fromFile(__DIR__ . '/there-is-no-such-file');

        self::assertSame([], $environment->all());
    }

    public function testTheCommittedTemplateParsesAndCarriesNoValues(): void
    {
        $environment = Environment::fromFile(__DIR__ . '/../../../../.env.example');

        self::assertNotSame([], $environment->all(), 'the template should declare the names a clone has to fill in');
        self::assertSame([''], array_values(array_unique(array_values($environment->all()))));
    }
}
