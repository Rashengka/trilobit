<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Installation;

use DateTimeImmutable;

/**
 * One business as the list in the installation's section shows it.
 *
 * It is a value and not the entity, for the reason every other list in this
 * application is drawn from one: a template handed a mapped object is a
 * template that can reach anywhere from it, and what is on the page should be
 * decided where the page is prepared.
 *
 * **What is deliberately not on it is where the business answers.** A host is
 * a row of core_domain, which is tenanted, so reading them for every business
 * at once would mean stepping around the tenant filter - see
 * Trilobit\Core\Tenancy\Businesses for why that exception is not made in
 * passing. **Exit condition:** the first screen that has to show or change
 * where a business answers.
 */
final readonly class BusinessSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public DateTimeImmutable $createdAt,
        /** Which of the three ways its addresses say what language they are in. */
        public string $languageStrategy,
    ) {}
}
