<?php

declare(strict_types=1);

namespace Trilobit\Cms\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Trilobit\Cms\Domain\Menu\MenuItem;
use Trilobit\Cms\Domain\Menu\MenuRepository;

/**
 * The arranged menus, kept in the database the rest of the application is kept
 * in.
 *
 * Every listing is ordered by the position somebody chose and then by the
 * label, so that two entries given the same position come out in an order that
 * is at least the same twice - the order rows happen to be returned in is not
 * an order anybody arranged.
 */
final readonly class DoctrineMenuRepository implements MenuRepository
{
    private const array ARRANGED = ['position' => 'ASC', 'label' => 'ASC'];

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function find(int $id): ?MenuItem
    {
        return $this->rows()->find($id);
    }

    /** @return list<MenuItem> */
    public function all(): array
    {
        return $this->rows()->findBy([], ['menu' => 'ASC', ...self::ARRANGED]);
    }

    /** @return list<MenuItem> */
    public function topOf(string $menu): array
    {
        return $this->rows()->findBy(
            ['menu' => $menu, 'parent' => null, 'visible' => true],
            self::ARRANGED,
        );
    }

    public function save(MenuItem $item): void
    {
        $this->entityManager->persist($item);
        $this->entityManager->flush();
    }

    public function remove(MenuItem $item): void
    {
        $this->entityManager->remove($item);
        $this->entityManager->flush();
    }

    /** @return EntityRepository<MenuItem> */
    private function rows(): EntityRepository
    {
        return $this->entityManager->getRepository(MenuItem::class);
    }
}
