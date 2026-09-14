<?php

declare(strict_types=1);

namespace Trilobit\Core\Admin\Menu;

/**
 * Core's entry for the people of a business: who belongs to it, and what each
 * of them holds there. A business's own section, like the navigation's - and,
 * like every entry, gone from the bar for anybody its page would refuse.
 */
final class PeopleMenu implements MenuProvider
{
    public function provide(): iterable
    {
        yield new MenuItem('People', 'Core:Admin:People:default');
    }
}
