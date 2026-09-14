<?php

declare(strict_types=1);

namespace Trilobit\Shop\Domain\Product;

/**
 * Where the bindings of products to their pictures are kept.
 *
 * Nothing in it names a business: every read of shop_product_image is scoped
 * by Trilobit\Core\Tenancy\TenantFilter.
 */
interface ProductImageRepository
{
    public function find(int $id): ?ProductImage;

    /** @return list<ProductImage> the pictures of $product, the first one first */
    public function of(Product $product): array;

    /** Where a picture added to $product now goes: after every one it has. */
    public function nextPosition(Product $product): int;

    public function save(ProductImage $picture): void;

    public function remove(ProductImage $picture): void;
}
