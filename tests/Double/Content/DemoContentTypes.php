<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Content;

use Trilobit\Core\Content\ContentType;
use Trilobit\Core\Content\ContentTypeProvider;
use Trilobit\Tests\Double\DemoModule;

/**
 * The module that is in every build here: it publishes sections, which nest,
 * and its pages link out to content another module owns.
 *
 * It stands in for the content side of the application the way
 * Trilobit\Tests\Double\Content\DemoCatalogueTypes stands in for the shop
 * side. Neither exists yet, and the mechanism they will both write into is
 * finished before either does - so the suites stand in for them rather than
 * inventing entities inside a real module, which would be a guess about what
 * is later built, committed to src/ and then in the way.
 */
final class DemoContentTypes implements ContentTypeProvider
{
    public const string SECTION = 'demo.section';

    /** @return list<ContentType> */
    public function contentTypes(): array
    {
        return [new ContentType(self::SECTION, DemoModule::PAGE, 'section')];
    }
}
