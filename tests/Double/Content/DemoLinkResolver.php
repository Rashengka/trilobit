<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Content;

use Trilobit\Core\Content\Address;
use Trilobit\Core\Content\PathLookup;
use Trilobit\Core\Contract\Content\ContentLink;
use Trilobit\Core\Contract\Content\ContentLinkResolver;
use Trilobit\Core\Contract\Content\ContentRef;

/**
 * The half of the switchable module that another module reaches it through:
 * turning a reference to one of its products into a link.
 *
 * It answers only for what its own module publishes. Everything else comes
 * back null - the same answer a caller gets in a build where this module is
 * not present at all, because there the port is filled in by
 * Trilobit\Core\Contract\Content\NullContentLinkResolver instead.
 */
final readonly class DemoLinkResolver implements ContentLinkResolver
{
    public function __construct(private PathLookup $paths) {}

    public function resolve(ContentRef $ref): ?ContentLink
    {
        if ($ref->type !== DemoCatalogueTypes::PRODUCT) {
            return null;
        }

        $path = $this->paths->canonicalPathOf($ref);
        if ($path === null) {
            return null;
        }

        $address = $this->paths->find($path);

        return $address instanceof Address ? new ContentLink('/' . $path, $address->label) : null;
    }
}
