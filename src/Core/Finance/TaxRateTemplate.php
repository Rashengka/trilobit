<?php

declare(strict_types=1);

namespace Trilobit\Core\Finance;

use DateTimeImmutable;

/**
 * The rates of tax of one country, as a template a business takes rates from
 * - see TaxRateTemplates for where it is read from and what is refused.
 */
final readonly class TaxRateTemplate
{
    /**
     * @param list<string> $sources where the rates were checked, each an https address
     * @param non-empty-list<TemplateRate> $rates in the order the file writes them
     */
    public function __construct(
        /** Two capital letters, meant as ISO 3166-1 alpha-2: CZ. */
        public string $country,
        /** The day somebody last checked the rates against the sources. */
        public DateTimeImmutable $verifiedAt,
        public array $sources,
        public array $rates,
    ) {}
}
