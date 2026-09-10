<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Admin;

use Trilobit\Core\Admin\Menu\MenuItem;
use Trilobit\Core\Admin\Menu\MenuProvider;

/**
 * A module putting a page of the public site on the administration bar.
 *
 * It is a fixture because no module does that any more, and the rule it
 * stands over is still Trilobit\Core\Admin\Menu\ReachableMenu's: an entry is
 * dropped by a gate that refuses it and never by there being no gate at all.
 * Two very different things carry none - a public page, which anybody may
 * open, and an administration page whose author declared nothing, which is a
 * mistake that is loud in two other places - so the filter has to tell them
 * apart by what a gate says rather than by whether one is there.
 *
 * Every module used to contribute an entry like this one, before Cms had an
 * administration and while a bar made only of what modules contributed would
 * otherwise have been empty. Written here instead, the rule keeps being
 * measured against a real page, a real router and a real filter, and the
 * application keeps its bar free of ways out of itself.
 *
 * The destination is a module's own status page: it is in every build this
 * suite makes, it takes no parameters, and it is not a page of the
 * administration - which is the whole of what makes it the shape being
 * measured.
 */
final class PublicPageMenu implements MenuProvider
{
    /** @return iterable<MenuItem> */
    public function provide(): iterable
    {
        yield new MenuItem('Shop', 'Shop:Front:Status:default');
    }
}
