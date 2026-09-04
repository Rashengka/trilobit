<?php

declare(strict_types=1);

namespace Trilobit\Core\Admin\Menu;

/**
 * A module contributes administration menu entries by registering a service
 * that implements this and carries the tag
 * Trilobit\Core\DI\CoreExtension::TAG_ADMIN_MENU_PROVIDER.
 */
interface MenuProvider
{
    /** @return iterable<MenuItem> */
    public function provide(): iterable;
}
