<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Shop\Domain\Price;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Shop\Domain\Price\Decimal;
use Trilobit\Shop\Domain\Price\PriceRefused;
use Trilobit\Shop\Domain\Price\VatRate;

/**
 * The rate of tax on a product, in basis points: 2100 is 21 %.
 *
 * Kept as a whole number so that the price with tax is worked out without a
 * fraction anywhere in it; written and read as a percentage with up to two
 * decimal places, which is every rate a basis point can say.
 */
#[CoversClass(VatRate::class)]
#[CoversClass(Decimal::class)]
#[CoversClass(PriceRefused::class)]
final class VatRateTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function writtenRates(): iterable
    {
        yield 'a whole percentage' => ['21', 2100];
        yield 'one decimal place' => ['12.5', 1250];
        yield 'a comma and two decimal places' => ['12,05', 1205];
        yield 'no tax' => ['0', 0];
        yield 'the largest' => ['100', 10000];
        yield 'spaces around it' => [' 21 ', 2100];
    }

    #[DataProvider('writtenRates')]
    public function testARateIsReadAsAPercentage(string $written, int $basisPoints): void
    {
        self::assertSame($basisPoints, VatRate::ofPercent($written)->basisPoints());
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableRates(): iterable
    {
        yield 'nothing written' => [''];
        yield 'words' => ['standard'];
        yield 'below nothing' => ['-1'];
        yield 'above a hundred' => ['100.01'];
        yield 'finer than a basis point' => ['1.234'];
    }

    #[DataProvider('unreadableRates')]
    public function testWhatCannotBeReadAsARateIsRefused(string $written): void
    {
        $this->expectException(PriceRefused::class);

        VatRate::ofPercent($written);
    }

    /** @return iterable<string, array{int}> */
    public static function outOfRange(): iterable
    {
        yield 'below nothing' => [-1];
        yield 'above a hundred per cent' => [10001];
    }

    #[DataProvider('outOfRange')]
    public function testARateOutsideNothingToAHundredPerCentIsRefused(int $basisPoints): void
    {
        $this->expectException(PriceRefused::class);

        new VatRate($basisPoints);
    }

    /** @return iterable<string, array{int, string}> */
    public static function percentages(): iterable
    {
        yield 'whole' => [2100, '21'];
        yield 'one decimal place' => [1250, '12.5'];
        yield 'two decimal places' => [1205, '12.05'];
        yield 'none' => [0, '0'];
    }

    #[DataProvider('percentages')]
    public function testARateIsWrittenAsTheShortestPercentage(int $basisPoints, string $percent): void
    {
        $rate = new VatRate($basisPoints);

        self::assertSame($percent, $rate->percent());
        self::assertSame($basisPoints, VatRate::ofPercent($rate->percent())->basisPoints());
    }
}
