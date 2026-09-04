<?php

declare(strict_types=1);

namespace Trilobit\Crm\Admin;

use Trilobit\Core\Admin\Menu\MenuItem;
use Trilobit\Core\Admin\Menu\MenuProvider;

/**
 * What the Crm module puts in the administration menu.
 *
 * It points at the module's own page for now, because a menu entry pointing at
 * a presenter that does not exist yet is an entry that throws the moment
 * somebody renders the menu. When the module grows an administration, this is
 * the one line that changes.
 */
final class CrmMenu implements MenuProvider
{
    /** @return iterable<MenuItem> */
    public function provide(): iterable
    {
        yield new MenuItem('Crm', 'Crm:Front:Status:default');
    }
}
