<?php

declare(strict_types=1);

namespace Trilobit\Shop\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Trilobit\Shop\Domain\Product\Product;
use Trilobit\Shop\Domain\Product\ProductRepository;

/**
 * Products, kept in the database the rest of the application is kept in.
 *
 * It flushes on every save, which is the right trade while a product is
 * written by a person filling in a form: one save is one request.
 */
final readonly class DoctrineProductRepository implements ProductRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function find(int $id): ?Product
    {
        return $this->rows()->find($id);
    }

    public function findBySku(string $sku): ?Product
    {
        return $this->rows()->findOneBy(['sku' => $sku]);
    }

    /** @return list<Product> */
    public function all(): array
    {
        return $this->rows()->findBy([], ['name' => 'ASC']);
    }

    public function save(Product $product): void
    {
        $this->entityManager->persist($product);
        $this->entityManager->flush();
    }

    public function remove(Product $product): void
    {
        $this->entityManager->remove($product);
        $this->entityManager->flush();
    }

    /** @return EntityRepository<Product> */
    private function rows(): EntityRepository
    {
        return $this->entityManager->getRepository(Product::class);
    }
}
