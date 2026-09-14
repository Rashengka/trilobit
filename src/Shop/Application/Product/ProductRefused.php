<?php

declare(strict_types=1);

namespace Trilobit\Shop\Application\Product;

use RuntimeException;

/**
 * A product that cannot be saved as it was written, for a reason about the
 * other products of the business rather than about its address - which is
 * Trilobit\Core\Content\PathRefused - or its price, which is
 * Trilobit\Shop\Domain\Price\PriceRefused.
 *
 * It is raised before anything is written, so the product stays as it was.
 */
final class ProductRefused extends RuntimeException
{
    public static function skuTaken(string $sku): self
    {
        return new self(sprintf(
            "Another product of this business already has the SKU '%s'. An SKU names one product; leave it empty if this one has none.",
            $sku,
        ));
    }
}
