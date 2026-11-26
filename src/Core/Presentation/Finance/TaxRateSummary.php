<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Finance;

/** One row of the list of rates of tax, worked out before the template draws it. */
final readonly class TaxRateSummary
{
    public function __construct(
        public int $id,
        public string $name,
        /** The rate as a person reads it, `12.5 %`; see VatRate::written(). */
        public string $percent,
        /** The two letters of the country, or '' for none. */
        public string $country,
        /** The kind in words, or '' for none. */
        public string $kind,
        public bool $isDefault,
        public bool $isArchived,
        /** The rate's own page. */
        public string $pageUrl,
    ) {}
}
