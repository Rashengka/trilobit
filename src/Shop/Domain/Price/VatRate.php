<?php

declare(strict_types=1);

namespace Trilobit\Shop\Domain\Price;

/**
 * The rate of tax on a product, in basis points: 2100 is 21 %, 1250 is 12.5 %
 * (.ai/plans/30-obchod-katalog-t09.md, V1).
 *
 * **A whole number, on the product, required.** Whole so that the price with
 * tax is worked out without a fraction anywhere in it (Money::withVat()); on
 * the product rather than in a list of rates the business keeps, because a
 * list can be added later by a migration that only adds, while taking one away
 * would be a migration that removes. **Exit condition for the list:** the day a
 * change of the law makes rewriting every product at one rate hurt.
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
}
