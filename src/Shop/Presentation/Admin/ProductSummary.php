<?php

declare(strict_types=1);

namespace Trilobit\Shop\Presentation\Admin;

/**
 * One product as the list in the administration shows it.
 *
 * Everything is worked out while the list is prepared rather than in the
 * template: an address comes from the register, a link from the router and a
 * price with tax from a sum, and a template doing any of them would be a
 * template holding a service.
 */
final readonly class ProductSummary
{
    public function __construct(
        public int $id,
        public string $name,
        /** '' where the product has none. */
        public string $sku,
        /** Before tax, as it is shown: `24,990.00 CZK`. */
        public string $price,
        public string $priceWithVat,
        public string $status,
        public bool $isPublished,
        /** The permalink with its leading slash, or a sentence saying there is none. */
        public string $address,
        public string $editUrl,
    ) {}
}
