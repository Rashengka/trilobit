<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nette\Security\User as SignedIn;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Presentation\Form\FormFactory;
use Trilobit\Core\Presentation\Listing\Comparison;
use Trilobit\Core\Presentation\Listing\Filters;
use Trilobit\Core\Presentation\Listing\Listing;

/**
 * Everybody who belongs to the business this request is in, filtered by name
 * and address and paged - on Trilobit\Core\Presentation\Listing\Listing, like
 * the list of pages.
 *
 * **What is listed is accounts, and what makes one belong here is a
 * membership.** An account belongs to no business (Trilobit\Core\Domain\User\
 * User is shared), so the query reads accounts that hold a role here: the
 * membership is read inside the condition, and Core's tenant filter scopes it
 * as it scopes every read of that table. Nobody of another business is in the
 * list, and nothing here says which business it is.
 *
 * The roles each person holds are read for the whole page at once rather than
 * row by row, through the same filter.
 */
final class PeopleListing extends Listing
{
    public function __construct(
        FormFactory $forms,
        private readonly EntityManagerInterface $entityManager,
        private readonly SignedIn $signedIn,
    ) {
        parent::__construct($forms);
    }

    protected function configure(Filters $filters): void
    {
        $filters
            ->text('name', 'Name', Comparison::Contains)
            ->text('email', 'Address', Comparison::Contains);
    }

    protected function query(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('person')
            ->from(User::class, 'person')
            ->where(sprintf('EXISTS (SELECT membership.id FROM %s membership WHERE membership.user = person)', Membership::class))
            ->orderBy('person.name', 'ASC');
    }

    /**
     * @param list<object> $items
     *
     * @return list<PersonSummary>
     */
    protected function rows(array $items): array
    {
        $people = [];
        foreach ($items as $item) {
            $id = $item instanceof User ? $item->id() : null;
            if ($item instanceof User && $id !== null) {
                $people[$id] = $item;
            }
        }

        $roles = $this->rolesOf(array_keys($people));
        $presenter = $this->getPresenter();

        $summaries = [];
        foreach ($people as $id => $person) {
            $summaries[] = new PersonSummary(
                $id,
                $person->name(),
                $person->email(),
                $roles[$id] ?? [],
                PersonSummary::stateOf($person),
                $presenter->link('person', ['id' => $id]),
                $this->signedIn->getId() === $id,
            );
        }

        return $summaries;
    }

    protected function rowsTemplate(): string
    {
        return __DIR__ . '/templates/PeopleListing.latte';
    }

    protected function caption(): string
    {
        return 'Everybody who belongs to this business, and what each of them holds here';
    }

    protected function counted(int $count): string
    {
        return $count === 1 ? '1 person' : sprintf('%d people', $count);
    }

    protected function nothingYet(): string
    {
        return 'Nobody belongs to this business yet.';
    }

    protected function nothingMatches(): string
    {
        return 'Nobody matches the filters.';
    }

    protected function testId(): string
    {
        return 'people-list';
    }

    /**
     * @param list<int> $people
     *
     * @return array<int, list<string>> the names of the roles each holds here, by the account
     */
    private function rolesOf(array $people): array
    {
        if ($people === []) {
            return [];
        }

        $rows = $this->entityManager
            ->createQuery(sprintf(
                'SELECT r.name AS role, IDENTITY(m.user) AS person FROM %s m JOIN m.role r WHERE m.user IN (:people) ORDER BY r.name ASC',
                Membership::class,
            ))
            ->setParameter('people', $people)
            ->getArrayResult();

        $held = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_numeric($row['person'] ?? null) && is_string($row['role'] ?? null)) {
                $held[(int) $row['person']][] = $row['role'];
            }
        }

        return $held;
    }
}
