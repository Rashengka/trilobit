<?php

declare(strict_types=1);

namespace Trilobit\Shop\Domain\Price;

use InvalidArgumentException;

/**
 * A price or a rate of tax that cannot be one, said in a sentence whoever typed
 * it can act on.
 *
 * Every refusal is made before anything is kept: a price is a value, and a
 * value that cannot be made leaves nothing behind to clean up.
 */
final class PriceRefused extends InvalidArgumentException
{
    public static function unreadablePrice(string $written): self
    {
        return new self(sprintf(
            "'%s' is not a price. Write it in digits, with a dot or a comma before at most %d decimal places.",
            $written,
            Money::DECIMALS,
        ));
    }

    public static function belowNothing(int $amount): self
    {
        return new self(sprintf('A price cannot be below nothing, and %d is.', $amount));
    }

    public static function tooLarge(int $amount): self
    {
        return new self(sprintf(
            'A price is at most %s in the smallest unit of its currency, and %d is more.',
            number_format(Money::MAX_AMOUNT, 0, '.', ','),
            $amount,
        ));
    }

    public static function notACurrency(string $currency): self
    {
        return new self(sprintf("'%s' is not a currency: a currency is its three capital letters, such as CZK.", $currency));
    }

    public static function unreadableRate(string $written): self
    {
        return new self(sprintf(
            "'%s' is not a rate of tax. Write it as a percentage, with at most two decimal places.",
            $written,
        ));
    }

    public static function rateOutOfRange(int $basisPoints): self
    {
        return new self(sprintf(
            'A rate of tax is between 0 and 100 %%, and %s %% is not.',
            number_format($basisPoints / 100, 2, '.', ''),
        ));
    }
}
