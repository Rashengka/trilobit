<?php

declare(strict_types=1);

namespace Trilobit\Core\Contract\Content;

/**
 * What answers ContentLinkResolver when no enabled module can turn a reference
 * into a link: nothing ever resolves.
 *
 * Trilobit\Core\DI\CoreExtension registers this in place of the port when
 * nothing else does, which is the whole of what lets a caller take
 * ContentLinkResolver as an ordinary constructor dependency instead of writing
 * a null check of its own; see .ai/plans/01a-komunikace-modulu.md §2.
 */
final class NullContentLinkResolver implements ContentLinkResolver
{
    public function resolve(ContentRef $ref): ?ContentLink
    {
        return null;
    }
}
