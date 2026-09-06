<?php

declare(strict_types=1);

namespace Trilobit\Cms\Content;

use Trilobit\Cms\Domain\Page\Page;
use Trilobit\Core\Content\ContentType;
use Trilobit\Core\Content\ContentTypeProvider;

/**
 * The kind of content this module publishes, and the page that draws it.
 *
 * This is the whole of what makes /about-us answer without any route being
 * written for it: the register turns the address into a type and an
 * identifier, and this turns the type into a presenter. Neither step mentions
 * a module, so a page of this module and a product of another sit beside each
 * other at the root of the site (decision R8).
 *
 * A build without this module registers none of this, so the rows its pages
 * left in the register are simply not routed - they wait for the module to
 * come back rather than answering with an error.
 */
final class CmsContentTypes implements ContentTypeProvider
{
    /** As the presenter mapping in src/Cms/config/services.neon turns a name into a class. */
    public const string PAGE = 'Cms:Front:Page';

    /** @return list<ContentType> */
    public function contentTypes(): array
    {
        return [new ContentType(Page::TYPE, self::PAGE)];
    }
}
