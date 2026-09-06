<?php

declare(strict_types=1);

namespace Trilobit\Cms\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Trilobit\Cms\Domain\Page\Page;
use Trilobit\Cms\Domain\Page\PageRepository;

/**
 * Pages, kept in the database the rest of the application is kept in.
 *
 * It flushes on every save, which is the right trade while a page is written
 * by a person filling in a form: one save is one request, and a unit of work
 * spanning more than that would be a unit of work nobody closes.
 */
final readonly class DoctrinePageRepository implements PageRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function find(int $id): ?Page
    {
        return $this->rows()->find($id);
    }

    /** @return list<Page> */
    public function all(): array
    {
        return $this->rows()->findBy([], ['title' => 'ASC']);
    }

    public function save(Page $page): void
    {
        $this->entityManager->persist($page);
        $this->entityManager->flush();
    }

    public function remove(Page $page): void
    {
        $this->entityManager->remove($page);
        $this->entityManager->flush();
    }

    /** @return EntityRepository<Page> */
    private function rows(): EntityRepository
    {
        return $this->entityManager->getRepository(Page::class);
    }
}
