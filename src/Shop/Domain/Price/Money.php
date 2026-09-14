<?php

declare(strict_types=1);

namespace Trilobit\Shop\Domain\Price;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An amount in the smallest unit of its currency - the hundredths of a crown,
 * cents - and the currency it is in (.ai/plans/30-obchod-katalog-t09.md, Q1).
 *
 * **Whole numbers, and the currency with every amount.** An amount is kept as
 * an integer so that adding tax to it is arithmetic rather than an estimate,
 * and the currency is kept beside it rather than assumed, so that the day an
 * installation changes its currency the prices it already has still say what
 * they were set in.
 *
 * **Every currency has two decimal places here**, which is true of every one a
 * shop in this project has been set up with. **Exit condition:** the first
 * installation in a currency with another number of them - at which point the
 * number belongs beside the currency rather than in a constant.
 *
 * It is embedded in the entity that has a price, as two columns of that entity's
 * table: a price is part of what it is the price of, and a table of prices would
 * be a second place to look for it.
 */
#[ORM\Embeddable]
final class Money
{
    /** Decimal places of every currency here; see the class. */
    public const int DECIMALS = 2;

    /**
     * The largest amount there may be, in the smallest unit: a little under a
     * trillion in whole units. Low enough that the amount times the largest
     * rate of tax - see withVat() - fits a 64-bit integer with room to spare,
     * so that adding tax cannot overflow; what it may do is go past this, and
     * that is refused rather than wrapped.
     */
    public const int MAX_AMOUNT = 99_999_999_999_999;

    /** Basis points in a whole: a rate of 10000 is 100 %. */
    private const int WHOLE = 10000;

    public function __construct(
        #[ORM\Column(type: Types::BIGINT)]
        private int $amount,
        /** ISO 4217, the three capital letters: CZK. */
        #[ORM\Column(length: 3)]
        private string $currency,
    ) {
        if ($amount < 0) {
            throw PriceRefused::belowNothing($amount);
        }

        if ($amount > self::MAX_AMOUNT) {
            throw PriceRefused::tooLarge($amount);
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw PriceRefused::notACurrency($currency);
        }
    }

    /** An amount written the way a person writes one - `12 990,50` - in $currency. */
    public static function ofDecimal(string $written, string $currency): self
    {
        $amount = Decimal::scaled($written, self::DECIMALS) ?? throw PriceRefused::unreadablePrice(trim($written));

        return new self($amount, $currency);
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * This amount with $rate of tax on it, in whole units of the smallest
     * unit, half a unit and more going up (decision V1):
     * `intdiv(amount × (10000 + rate) + 5000, 10000)`.
     */
    public function withVat(VatRate $rate): self
    {
        return new self(
            intdiv($this->amount * (self::WHOLE + $rate->basisPoints()) + intdiv(self::WHOLE, 2), self::WHOLE),
            $this->currency,
        );
    }

    /** `12,990.50 CZK`: for reading, not for reading back. */
    public function format(): string
    {
        return sprintf('%s.%s %s', number_format($this->whole(), 0, '.', ','), $this->hundredths(), $this->currency);
    }

    /** `12990.50`: what a form is filled in with, and what ofDecimal() reads back as the same amount. */
    public function decimal(): string
    {
        return $this->whole() . '.' . $this->hundredths();
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    private function whole(): int
    {
        return intdiv($this->amount, 10 ** self::DECIMALS);
    }

    private function hundredths(): string
    {
        return str_pad((string) ($this->amount % 10 ** self::DECIMALS), self::DECIMALS, '0', STR_PAD_LEFT);
    }
}
