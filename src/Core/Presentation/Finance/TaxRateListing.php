<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Finance;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Trilobit\Core\Domain\Finance\TaxRate;
use Trilobit\Core\Domain\Finance\TaxRateKind;
use Trilobit\Core\Presentation\Form\FormFactory;
use Trilobit\Core\Presentation\Listing\Comparison;
use Trilobit\Core\Presentation\Listing\Filters;
use Trilobit\Core\Presentation\Listing\Listing;

/**
 * The rates of tax of the business this request is in, filtered and paged -
 * on Trilobit\Core\Presentation\Listing\Listing, like the list of people.
 *
 * The rates still offered come first, in their order, and the archived ones
 * after them: an archived rate is kept for what is priced at it, and a list
 * that mixed the two would put a rate nobody may choose beside the ones they
 * may. Core's tenant filter scopes the query, so no rate of another business
 * is in it. Every row leads to the rate's own page: whoever sees the list may
 * open it.
 */
final class TaxRateListing extends Listing
{
    public function __construct(
        FormFactory $forms,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct($forms);
    }

    protected function configure(Filters $filters): void
    {
        $filters
            ->text('name', 'Name', Comparison::Contains)
            ->text('country', 'Country', Comparison::Equals)
            ->choice('kind', 'Kind', TaxRateKind::choices(), Comparison::Equals);
    }

    protected function query(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('rate')
            ->addSelect('CASE WHEN rate.archivedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN archived')
            ->from(TaxRate::class, 'rate')
            ->orderBy('archived', 'ASC')
            ->addOrderBy('rate.position', 'ASC')
            ->addOrderBy('rate.id', 'ASC');
    }

    /**
     * @param list<object> $items
     *
     * @return list<TaxRateSummary>
     */
    protected function rows(array $items): array
    {
        $presenter = $this->getPresenter();

        $rows = [];
        foreach ($items as $rate) {
            $id = $rate instanceof TaxRate ? $rate->id() : null;
            if (!$rate instanceof TaxRate || $id === null) {
                continue;
            }

            $rows[] = new TaxRateSummary(
                $id,
                $rate->name(),
                $rate->rate()->written(),
                $rate->country() ?? '',
                $rate->kind()?->label() ?? '',
                $rate->isDefault(),
                $rate->isArchived(),
                $presenter->link('edit', ['id' => $id]),
            );
        }

        return $rows;
    }

    protected function rowsTemplate(): string
    {
        return __DIR__ . '/templates/TaxRateListing.latte';
    }

    protected function caption(): string
    {
        return 'The rates of tax this business charges, the ones still offered first';
    }

    protected function counted(int $count): string
    {
        return $count === 1 ? '1 rate' : sprintf('%d rates', $count);
    }

    protected function nothingYet(): string
    {
        return 'This business has no rate of tax yet.';
    }

    protected function nothingMatches(): string
    {
        return 'No rate matches the filters.';
    }

    protected function testId(): string
    {
        return 'tax-rate-list';
    }
}
