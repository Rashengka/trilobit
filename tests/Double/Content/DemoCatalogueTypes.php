<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Content;

use Trilobit\Core\Content\ContentType;
use Trilobit\Core\Content\ContentTypeProvider;
use Trilobit\Tests\Double\DemoModule;

/**
 * The module a build can be without: it publishes products, and it is the only
 * thing that can turn a reference to one into a link.
 *
 * Switching it off is what makes the two claims worth making measurable - an
 * address of its content stops being routed, and a page linking to one draws
 * no anchor rather than an empty one or an error.
 */
final class DemoCatalogueTypes implements ContentTypeProvider
{
    public const string PRODUCT = 'demo.product';

    /** @return list<ContentType> */
    public function contentTypes(): array
    {
        return [new ContentType(self::PRODUCT, DemoModule::PAGE, 'product')];
    }
}
