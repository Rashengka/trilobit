<?php

declare(strict_types=1);

namespace Trilobit\Core\Finance;

use Trilobit\Core\Domain\Finance\TaxRateKind;
use Trilobit\Core\Domain\Finance\VatRate;

/**
 * One rate of a template of a country's rates of tax, as its file says it -
 * see TaxRateTemplates.
 *
 * templateKey is what a business keeps the rate under once it has taken it
 * (Trilobit\Core\Domain\Finance\TaxRate::templateKey()): the country in small
 * letters and the rate's key in its file, `cz.standard`. It names which rate
 * of the country it is and never what the rate is, so it stays the same when
 * the law changes the percent.
 */
final readonly class TemplateRate
{
    public function __construct(
        public string $templateKey,
        /** Two capital letters, the template's country. */
        public string $country,
        public TaxRateKind $kind,
        public VatRate $rate,
        /** What the rate is called once a business takes it. */
        public string $label,
    ) {}

    /** `21 % - Standard rate`, the way Trilobit\Core\Domain\Finance\TaxRate::label() writes a rate a business holds. */
    public function written(): string
    {
        return sprintf('%s - %s', $this->rate->written(), $this->label);
    }
}
