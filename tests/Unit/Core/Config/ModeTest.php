<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Config\Environment;
use Trilobit\Core\Config\Mode;
use Trilobit\Core\Config\ModeNotNamed;

#[CoversClass(Mode::class)]
final class ModeTest extends TestCase
{
    public function testAnAbsentModeIsProduction(): void
    {
        self::assertSame(Mode::Prod, Mode::fromEnvironment(Environment::fromValues([])));
    }

    public function testAnEmptyModeIsProduction(): void
    {
        self::assertSame(Mode::Prod, Mode::fromEnvironment(Environment::fromString("TRILOBIT_ENV=\n")));
    }

    /**
     * Anything but the three names is production, spelled differently or not:
     * the safe direction for a value nobody meant is a closed application, not
     * an open debugger.
     */
    #[DataProvider('unknownValues')]
    public function testAnUnknownModeIsProduction(string $value): void
    {
        self::assertSame(Mode::Prod, Mode::fromEnvironment(Environment::fromValues(['TRILOBIT_ENV' => $value])));
    }

    /** @return iterable<string, array{string}> */
    public static function unknownValues(): iterable
    {
        yield 'a word nobody defined' => ['debug'];
        yield 'the long spelling' => ['production'];
        yield 'another case' => ['DEV'];
        yield 'a flag' => ['1'];
    }

    #[DataProvider('knownValues')]
    public function testEachNamedModeIsItself(string $value, Mode $expected): void
    {
        self::assertSame($expected, Mode::fromEnvironment(Environment::fromValues(['TRILOBIT_ENV' => $value])));
    }

    /** @return iterable<string, array{string, Mode}> */
    public static function knownValues(): iterable
    {
        yield 'dev' => ['dev', Mode::Dev];
        yield 'staging' => ['staging', Mode::Staging];
        yield 'prod' => ['prod', Mode::Prod];
    }

    /**
     * The one combination that refuses to start: the retired switch is on and
     * nothing says which mode is meant. Falling back to production there would
     * take the debugger and the style guide away from a machine that had both,
     * and say nothing about it.
     */
    public function testTheRetiredSwitchWithoutAModeRefusesToStart(): void
    {
        $this->expectException(ModeNotNamed::class);
        $this->expectExceptionMessage('TRILOBIT_DEBUG is set and TRILOBIT_ENV is not');

        Mode::fromEnvironment(Environment::fromValues(['TRILOBIT_DEBUG' => '1']));
    }

    public function testTheRefusalSaysWhatToWriteInstead(): void
    {
        try {
            Mode::fromEnvironment(Environment::fromValues(['TRILOBIT_DEBUG' => '1']));
            self::fail('the retired switch was accepted without a mode');
        } catch (ModeNotNamed $refusal) {
            self::assertStringContainsString('TRILOBIT_ENV=dev', $refusal->getMessage());
        }
    }

    /** An empty mode is an absent one, so it does not get past the refusal either. */
    public function testTheRetiredSwitchWithAnEmptyModeRefusesToStart(): void
    {
        $this->expectException(ModeNotNamed::class);

        Mode::fromEnvironment(Environment::fromValues(['TRILOBIT_DEBUG' => '1', 'TRILOBIT_ENV' => '']));
    }

    /**
     * The retired switch left empty - which is what a .env copied from the old
     * template carries - was off, and off is what production already is.
     */
    public function testTheRetiredSwitchLeftEmptyIsNoReasonToRefuse(): void
    {
        self::assertSame(Mode::Prod, Mode::fromEnvironment(Environment::fromString("TRILOBIT_DEBUG=\n")));
    }

    public function testWithBothTheModeWinsAndTheRetiredSwitchIsNotRead(): void
    {
        self::assertSame(
            Mode::Prod,
            Mode::fromEnvironment(Environment::fromValues(['TRILOBIT_DEBUG' => '1', 'TRILOBIT_ENV' => 'prod'])),
        );
    }

    /**
     * The shape a container meets during the change-over: the old switch still
     * in the file, the mode set by the process.
     */
    public function testAModeFromTheProcessCountsBesideTheRetiredSwitchInTheFile(): void
    {
        self::assertSame(
            Mode::Dev,
            Mode::fromEnvironment(Environment::fromValues(['TRILOBIT_DEBUG' => '1'], ['TRILOBIT_ENV' => 'dev'])),
        );
    }

    public function testDebugModeIsOnWhileDevelopingAndOnStaging(): void
    {
        self::assertTrue(Mode::Dev->debugMode());
        self::assertTrue(Mode::Staging->debugMode());
        self::assertFalse(Mode::Prod->debugMode());
    }

    public function testTheStyleGuideIsThereWhileDevelopingAndOnStaging(): void
    {
        self::assertTrue(Mode::Dev->hasStyleguide());
        self::assertTrue(Mode::Staging->hasStyleguide());
        self::assertFalse(Mode::Prod->hasStyleguide());
    }

    /** Staging runs on real data, so it is refused exactly what production is. */
    public function testOnlyDevelopmentMayAlterData(): void
    {
        self::assertTrue(Mode::Dev->mayAlterData());
        self::assertFalse(Mode::Staging->mayAlterData());
        self::assertFalse(Mode::Prod->mayAlterData());
    }
}
