<?php

declare(strict_types=1);

namespace Trilobit\Shop\Application\Product;

use Trilobit\Shop\Domain\Price\Money;
use Trilobit\Shop\Domain\Price\VatRate;

/**
 * What the installation says about prices: the currency every new price is set
 * in, and the rate of tax a new product starts with.
 *
 * Both come from the configuration (`shop.currency` and `shop.vatRate` in
 * src/Shop/config/services.neon, overridden from config/local.neon), because
 * they are facts about where the shop trades rather than about any product.
 * The currency is stored with every price all the same (decision Q1), so
 * changing it here changes what new prices are set in and leaves every price
 * already set saying what it was set in.
 *
 * Both are checked the moment this is made, the way a price or a rate is -
 * a currency that is not three capital letters is refused loudly, rather than
 * found out the first time somebody saves a product.
 */
final readonly class PriceSettings
{
    public string $currency;

    public VatRate $defaultRate;

    public function __construct(string $currency, int $defaultVatRate)
    {
        $this->currency = new Money(0, $currency)->currency();
        $this->defaultRate = new VatRate($defaultVatRate);
    }

    /** No price at all, in the installation's currency - what a product starts at when nobody may set one. */
    public function nothing(): Money
    {
        return new Money(0, $this->currency);
    }
}
