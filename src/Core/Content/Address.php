<?php

declare(strict_types=1);

namespace Trilobit\Core\Content;

use Trilobit\Core\Contract\Content\ContentRef;

/**
 * What the register says about one public address.
 *
 * It is a value rather than the entity, because the router and the presenters
 * are on the reading side of the application and have no business holding
 * something the object-relational mapper will write back.
 *
 * `canonicalPath` is filled in even when it is this very address, so that a
 * page can state it without asking whether it is the canonical one - the same
 * reason a null implementation stands behind every port. A page reached at a
 * non-canonical address answers 200 and names the canonical one in
 * <link rel="canonical">; it does not redirect, because the address a visitor
 * arrived at is the context the link was given in.
 */
final readonly class Address
{
    public function __construct(
        public string $path,
        public ContentRef $ref,
        public string $label,
        public string $canonicalPath,
        /** The address above this one in the tree, or null for one at the root. */
        public ?string $parentPath,
        /** Where this address moved to, or null while it is live. */
        public ?string $movedTo,
    ) {}

    public function hasMoved(): bool
    {
        return $this->movedTo !== null;
    }

    public function isCanonical(): bool
    {
        return $this->path === $this->canonicalPath;
    }
}
