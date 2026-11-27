<?php

declare(strict_types=1);

namespace Trilobit\Core\Finance;

/**
 * Something Trilobit\Core\Finance\TaxRates would not do to a rate of tax, and
 * the sentence saying why.
 *
 * It is a refusal and not a fault: the message is written for the person who
 * asked, and a page shows it to them as it is. The rates stay as they were:
 * this is raised before anything is written, or - as TaxRateConflict - after
 * the database refused a write and nothing of it was kept.
 */
class TaxRateRefused extends \RuntimeException {}
