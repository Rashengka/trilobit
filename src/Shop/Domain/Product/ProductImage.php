<?php

declare(strict_types=1);

namespace Trilobit\Shop\Domain\Product;

use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Domain\Media\MediaFile;
use Trilobit\Core\Domain\Tenancy\Tenant;

/**
 * A picture of a product: the binding of the product to a file of Core's media
 * library, and its place among the product's other pictures
 * (.ai/plans/30-obchod-katalog-t09.md, Q4).
 *
 * **The picture is Core's and the binding is the shop's.** One file can be
 * shown by a product and a page at once, and neither module may know about
 * the other, so the file is a Trilobit\Core\Domain\Media\MediaFile - the one
 * entity of Core a module may point a foreign key at - and this row says only
 * that this product shows it, and where.
 *
 * **Taking a picture off a product removes this row and nothing else**, and so
 * does deleting the product. The file stays in the library: deleting files is
 * for .ai/plans/16-soft-delete.md, when there is a bin to take them back out
 * of. The foreign key to the product does not cascade for the same reason a
 * page's addresses are not left to the database: whoever deletes a product
 * takes its pictures off first, where Doctrine sees it.
 *
 * It carries the business like everything else of it, so every read of the
 * table is scoped by Trilobit\Core\Tenancy\TenantFilter.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shop_product_image')]
#[ORM\UniqueConstraint(name: 'uniq_product_picture', columns: ['product_id', 'file_id'])]
class ProductImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Product::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Product $product,
        #[ORM\ManyToOne(targetEntity: MediaFile::class)]
        #[ORM\JoinColumn(nullable: false)]
        private MediaFile $file,
        /** Where among the product's pictures this one is: the lowest first. */
        #[ORM\Column]
        private int $position,
    ) {}

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function product(): Product
    {
        return $this->product;
    }

    public function file(): MediaFile
    {
        return $this->file;
    }

    public function position(): int
    {
        return $this->position;
    }
}
