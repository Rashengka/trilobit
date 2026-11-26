<?php

declare(strict_types=1);

namespace Trilobit\Shop\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Trilobit\Shop\Domain\Product\Product;
use Trilobit\Shop\Domain\Product\ProductImage;
use Trilobit\Shop\Domain\Product\ProductImageRepository;

/**
 * The bindings of products to their pictures, in the database the rest of the
 * application is kept in. It flushes on every save, as the product repository
 * does: one save is one request of a person working a form.
 */
final readonly class DoctrineProductImageRepository implements ProductImageRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function find(int $id): ?ProductImage
    {
        return $this->rows()->find($id);
    }

    /** @return list<ProductImage> */
    public function of(Product $product): array
    {
        return $this->rows()->findBy(['product' => $product], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function nextPosition(Product $product): int
    {
        $pictures = $this->of($product);
        $last = end($pictures);

        return $last instanceof ProductImage ? $last->position() + 1 : 0;
    }

    public function save(ProductImage $picture): void
    {
        $this->entityManager->persist($picture);
        $this->entityManager->flush();
    }

    public function remove(ProductImage $picture): void
    {
        $this->entityManager->remove($picture);
        $this->entityManager->flush();
    }

    /** @return EntityRepository<ProductImage> */
    private function rows(): EntityRepository
    {
        return $this->entityManager->getRepository(ProductImage::class);
    }
}
