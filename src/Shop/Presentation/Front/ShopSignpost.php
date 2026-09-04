<?php

declare(strict_types=1);

namespace Trilobit\Shop\Presentation\Front;

use Trilobit\Core\Presentation\Front\Signpost\Signpost;
use Trilobit\Core\Presentation\Front\Signpost\SignpostProvider;

/**
 * What the Shop module puts on the homepage as its way in.
 *
 * It points at the module's own page for now, because a link into a
 * presenter that does not exist yet is a link that throws the moment
 * somebody renders the homepage. When the module grows a catalogue, this is
 * the one line that changes.
 */
final class ShopSignpost implements SignpostProvider
{
    /** @return iterable<Signpost> */
    public function provide(): iterable
    {
        yield new Signpost('Shop', 'Shop:Front:Status:default');
    }
}
