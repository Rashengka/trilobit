<?php

declare(strict_types=1);

namespace Trilobit\Core\Admin\Menu;

/**
 * Core's entries for a business's money matters: its rates of tax today. A
 * business's own section, like the people's - and, like every entry, gone
 * from the bar for anybody its page would refuse.
 *
 * The section's signpost at /admin/finance is drawn from the entries here
 * (Trilobit\Core\Presentation\Finance\SignpostPresenter), so a page joining
 * the section is one more entry and nothing else.
 */
final class FinanceMenu implements MenuProvider
{
    public function provide(): iterable
    {
        yield new MenuItem('Tax rates', 'Core:Finance:TaxRates:default');
    }
}
