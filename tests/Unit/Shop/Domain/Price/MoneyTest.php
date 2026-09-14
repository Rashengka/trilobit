<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Shop\Domain\Price;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Shop\Domain\Price\Decimal;
use Trilobit\Shop\Domain\Price\Money;
use Trilobit\Shop\Domain\Price\PriceRefused;
use Trilobit\Shop\Domain\Price\VatRate;

/**
 * A price in the smallest unit of its currency, and what it comes to with tax.
 *
 * The rounding is the claim worth a data provider: it is done in whole numbers
 * only, half up, and every boundary around the half is written out - the
 * exact half, a hair either side of it, nothing, and the largest amount at the
 * largest rate, which has to come out without the arithmetic overflowing.
 */
#[CoversClass(Money::class)]
#[CoversClass(Decimal::class)]
#[CoversClass(PriceRefused::class)]
final class MoneyTest extends TestCase
{
    /** @return iterable<string, array{int, int, int}> the price before tax, the rate in basis points, the price with it */
    public static function withTax(): iterable
    {
        yield 'an ordinary price at the standard rate' => [100, 2100, 121];
        yield 'less than a unit of tax is dropped' => [1, 2100, 1];
        yield 'exactly half a unit goes up' => [5, 1000, 6];
        yield 'exactly half a unit goes up above one too' => [15, 1000, 17];
        yield 'a quarter of a unit goes down' => [25, 2100, 30];
        yield 'a hair under half goes down' => [1, 4999, 1];
        yield 'half at the rate that makes it half goes up' => [1, 5000, 2];
        yield 'nothing is nothing at any rate' => [0, 2100, 0];
        yield 'no tax leaves the price' => [12345, 0, 12345];
        yield 'the largest rate doubles it' => [12345, 10000, 24690];
        yield 'half the largest amount at the largest rate' => [intdiv(Money::MAX_AMOUNT, 2), 10000, 2 * intdiv(Money::MAX_AMOUNT, 2)];
    }

    #[DataProvider('withTax')]
    public function testThePriceWithTaxIsRoundedHalfUpInWholeUnits(int $net, int $basisPoints, int $gross): void
    {
        $price = new Money($net, 'CZK')->withVat(new VatRate($basisPoints));

        self::assertSame($gross, $price->amount());
        self::assertSame('CZK', $price->currency());
    }

    /**
     * The arithmetic itself cannot overflow - the largest amount times the
     * largest rate fits a 64-bit integer with room to spare - so what goes past
     * the largest amount is the answer, and it is refused rather than wrapped
     * or cut to fit.
     */
    public function testAPriceWhoseTaxTakesItPastTheLargestIsRefused(): void
    {
        $this->expectException(PriceRefused::class);

        new Money(Money::MAX_AMOUNT, 'CZK')->withVat(new VatRate(10000));
    }

    /** @return iterable<string, array{string, int}> */
    public static function writtenPrices(): iterable
    {
        yield 'whole units' => ['12990', 1299000];
        yield 'one decimal place' => ['12990.5', 1299050];
        yield 'a comma and spaces between thousands' => ['12 990,50', 1299050];
        yield 'a space that does not break' => ["12\u{00A0}990.50", 1299050];
        yield 'nothing at all' => ['0', 0];
        yield 'the smallest unit' => ['0.01', 1];
        yield 'spaces around it' => [' 7.00 ', 700];
    }

    #[DataProvider('writtenPrices')]
    public function testAPriceIsReadAsSomebodyWritesIt(string $written, int $amount): void
    {
        self::assertSame($amount, Money::ofDecimal($written, 'CZK')->amount());
    }

    /** @return iterable<string, array{string}> */
    public static function unreadablePrices(): iterable
    {
        yield 'nothing written' => [''];
        yield 'words' => ['twelve'];
        yield 'below nothing' => ['-5'];
        yield 'more decimal places than the currency has' => ['1.234'];
        yield 'both separators, so neither is certain' => ['1,234.56'];
        yield 'a number the way a machine writes it' => ['1e3'];
        yield 'one whole unit more than the largest price' => [sprintf('%d.00', intdiv(Money::MAX_AMOUNT, 100) + 1)];
        yield 'more digits than a price can have' => [str_repeat('9', 19)];
    }

    #[DataProvider('unreadablePrices')]
    public function testWhatCannotBeReadAsAPriceIsRefusedWithASentence(string $written): void
    {
        $this->expectException(PriceRefused::class);

        Money::ofDecimal($written, 'CZK');
    }

    public function testAnAmountBelowNothingIsRefused(): void
    {
        $this->expectException(PriceRefused::class);

        new Money(-1, 'CZK');
    }

    public function testAnAmountAboveTheLargestIsRefused(): void
    {
        $this->expectException(PriceRefused::class);

        new Money(Money::MAX_AMOUNT + 1, 'CZK');
    }

    /** @return iterable<string, array{string}> */
    public static function notCurrencies(): iterable
    {
        yield 'lower case' => ['czk'];
        yield 'too short' => ['CZ'];
        yield 'too long' => ['CZKK'];
        yield 'nothing' => [''];
    }

    #[DataProvider('notCurrencies')]
    public function testACurrencyIsThreeCapitalLetters(string $currency): void
    {
        $this->expectException(PriceRefused::class);

        new Money(100, $currency);
    }

    public function testAPriceIsShownWithItsThousandsApartAndItsCurrency(): void
    {
        self::assertSame('12,990.50 CZK', new Money(1299050, 'CZK')->format());
        self::assertSame('0.05 CZK', new Money(5, 'CZK')->format());
        self::assertSame('0.00 CZK', new Money(0, 'CZK')->format());
    }

    /** What a form is filled in with, which reads back as the same amount. */
    public function testAPriceIsWrittenBackTheWayItIsRead(): void
    {
        $price = new Money(1299050, 'CZK');

        self::assertSame('12990.50', $price->decimal());
        self::assertSame(1299050, Money::ofDecimal($price->decimal(), 'CZK')->amount());
    }
}
