<?php

declare(strict_types=1);

namespace Trilobit\Cms\Domain\Menu;

/**
 * The three kinds of thing a menu entry can point at.
 *
 * They are three rather than one because what has to be checked before the
 * entry is drawn is different for each, and a single "address" column would
 * hide that difference behind a string.
 *
 * - **Page** is a page of this module, held as an association, so deleting the
 *   page takes the entry with it.
 * - **Url** is written out by whoever made the entry and leads wherever they
 *   said; nothing here can check it and nothing pretends to.
 * - **Route** names a page of some module in this installation, as a presenter
 *   and an action. It is the one that may point into a module that is switched
 *   off, which is why it is a stored name rather than a foreign key: a foreign
 *   key across that boundary is what makes a module impossible to switch off
 *   (.ai/plans/01-architektura.md §3.5), and a name costs nothing to keep while
 *   the module is away.
 */
enum MenuTarget: string
{
    case Page = 'page';

    case Url = 'url';

    case Route = 'route';
}
