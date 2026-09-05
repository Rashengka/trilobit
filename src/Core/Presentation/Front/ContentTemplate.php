<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front;

use Trilobit\Core\Presentation\Front\Navigation\Crumb;

/**
 * What a page reached through the register of public addresses may rely on,
 * whichever module draws it.
 *
 * The trail is here rather than on Trilobit\Core\Presentation\Front\
 * FrontTemplate because only a page with an address in the register has one -
 * the homepage and the style guide are reached by static routes and are under
 * nothing. The canonical address is on FrontTemplate instead, because the
 * shared layout is what writes it into the head of the document and the layout
 * is written against that class.
 */
class ContentTemplate extends FrontTemplate
{
    /** @var list<Crumb> from the root down to the page itself */
    public array $breadcrumbs = [];
}
