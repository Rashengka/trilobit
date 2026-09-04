<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Front;

use Trilobit\Core\Presentation\Front\Signpost\Signpost;
use Trilobit\Core\Presentation\Front\Signpost\SignpostProvider;

/**
 * What the Cms module puts on the homepage as its way in.
 *
 * It points at the module's own page for now, because a link into a
 * presenter that does not exist yet is a link that throws the moment
 * somebody renders the homepage. When the module grows pages of its own,
 * this is the one line that changes.
 */
final class CmsSignpost implements SignpostProvider
{
    /** @return iterable<Signpost> */
    public function provide(): iterable
    {
        yield new Signpost('Cms', 'Cms:Front:Status:default', 'Pages, menus and the blocks they are built out of.');
    }
}
