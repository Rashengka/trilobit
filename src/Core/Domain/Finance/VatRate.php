<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\Finance;

/**
 * A rate of tax, in basis points: 2100 is 21 %, 1250 is 12.5 %.
 *
 * **A whole number**, so that a price with tax is worked out without a
 * fraction anywhere in it (Money::withVat()), and so that any rate a law
 * sets - 4.9 % as readily as 21 % - is one value and not an approximation.
 *
 * It is a value and nothing more: which rates a business charges, what each is
 * called and which is its default are the rows of its list of rates,
 * Trilobit\Core\Domain\Finance\TaxRate, whose rate() is one of these. A
 * product of the shop still carries its rate as a number of its own rather
 * than as one of those rows. **Exit condition:** the product choosing a rate
 * of its business's list, which takes a migration of every product's number.
 */
final readonly class VatRate
{
    /** A hundred per cent. */
    public const int MAX = 10000;

    /** Decimal places a percentage is written with: a basis point is a hundredth of one. */
    private const int DECIMALS = 2;

    public function __construct(private int $basisPoints)
    {
        if ($basisPoints < 0 || $basisPoints > self::MAX) {
            throw PriceRefused::rateOutOfRange($basisPoints);
        }
    }

    /** A rate written as a percentage - `21`, `12.5`, `12,05`. */
    public static function ofPercent(string $written): self
    {
        return new self(Decimal::scaled($written, self::DECIMALS) ?? throw PriceRefused::unreadableRate(trim($written)));
    }

    public function basisPoints(): int
    {
        return $this->basisPoints;
    }

    /** The shortest percentage saying this rate - `21`, `12.5` - which ofPercent() reads back as the same rate. */
    public function percent(): string
    {
        $whole = intdiv($this->basisPoints, 100);
        $fraction = rtrim(str_pad((string) ($this->basisPoints % 100), self::DECIMALS, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $whole : $whole . '.' . $fraction;
    }

    /**
     * The rate as a person reads it - `21 %` - with a no-break space between
     * the number and the sign, so that a narrow column never puts the two on
     * separate lines. percent() is what a form is filled with; this is what a
     * page says.
     */
    public function written(): string
    {
        return $this->percent() . "\u{a0}%";
    }
}
