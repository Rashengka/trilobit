<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Listing;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use Nette\Utils\Paginator;

/**
 * One page of a query: the rows on it, how many there are in all, and the
 * Nette paginator both are numbered by.
 *
 * **The count is taken over the query the rows are read from** - the same
 * builder, already narrowed by the filters - by Doctrine's own paginator,
 * which counts what the query would return, joins and all. .ai/plans/15 names
 * the trap: a count taken over a condition of its own numbers the pages right
 * and fills them wrong.
 *
 * **The order is made total.** The entity's identifier is added after
 * whatever the listing sorted by, because rows that tie in the order may come
 * back in a different order for each page, and one is then shown twice while
 * another is never shown.
 *
 * **A page past the last is the last, and says so.** Nette's paginator clamps
 * the page; what is added here is the sentence, because an address asking for
 * page 99 of a list of three would otherwise show page three and leave whoever
 * opened it wondering where the rest went.
 *
 * The builder handed in is left as it was: what is added is added to a copy.
 */
final readonly class Slice
{
    /**
     * @param list<object> $items the rows on this page, as the query returns them
     */
    private function __construct(
        public array $items,
        public int $total,
        public Paginator $paginator,
        public ?string $setAside,
    ) {}

    public static function of(QueryBuilder $query, int $page, int $perPage): self
    {
        $rows = clone $query;
        $aliases = $rows->getRootAliases();
        $entities = $rows->getRootEntities();
        if (count($aliases) !== 1 || count($entities) !== 1) {
            throw new \LogicException(sprintf('A listing pages through a query with one root, not %d.', count($aliases)));
        }

        foreach ($rows->getEntityManager()->getClassMetadata($entities[0])->getIdentifierFieldNames() as $identifier) {
            $rows->addOrderBy($aliases[0] . '.' . $identifier, 'ASC');
        }

        $doctrine = new DoctrinePaginator($rows);
        $total = count($doctrine);

        $paginator = new Paginator();
        $paginator->setItemsPerPage($perPage);
        $paginator->setItemCount($total);
        $paginator->setPage($page);

        $setAside = null;
        if ($paginator->getPage() !== $page) {
            $pages = max(1, (int) $paginator->getPageCount());
            $setAside = sprintf(
                'There is no page %d: the list has %d %s, and the last is shown.',
                $page,
                $pages,
                $pages === 1 ? 'page' : 'pages',
            );
        }

        $doctrine->getQuery()
            ->setFirstResult($paginator->getOffset())
            ->setMaxResults(max(1, $paginator->getLength()));

        $items = [];
        foreach ($doctrine as $item) {
            if (is_object($item)) {
                $items[] = $item;
            }
        }

        return new self($items, $total, $paginator, $setAside);
    }
}
