<?php

declare(strict_types=1);

namespace Trilobit\Core\Content;

use Trilobit\Core\Contract\Content\ContentRef;

/**
 * Reading the register of public addresses, which is all the router and the
 * pages ever do with it.
 *
 * It is separated from the writing side on purpose. Reading happens on every
 * request that is not a static route and has to stay one exact lookup over a
 * unique index; writing happens when somebody saves, carries every check in
 * Trilobit\Core\Content\PathRefused, and rewrites whole branches of the tree.
 * Naming the reading half also lets anything that only reads - the router
 * above all - be exercised without a database.
 */
interface PathLookup
{
    /** What answers at $path, or null when nobody claims it. */
    public function find(string $path): ?Address;

    /**
     * The permalink of one piece of content, or null when it has no address
     * at all.
     *
     * It is what a link points at unless the caller is already standing on
     * another address of the same content, and it is the only address a
     * sitemap is told about.
     */
    public function canonicalPathOf(ContentRef $ref): ?string;
}
