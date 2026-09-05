<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Content;

use Trilobit\Core\Content\ContentType;
use Trilobit\Core\Content\ContentTypeProvider;
use Trilobit\Tests\Double\DemoModule;

/**
 * A module that publishes content, without there being a module.
 *
 * The mechanism under test is the register of public addresses and the
 * catch-all that reads it, and both are finished before the first module has
 * anything to put in them. Standing in for that module here rather than
 * inventing a content entity inside one is the difference between testing the
 * mechanism and testing a guess about what will later be built on it.
 *
 * The two kinds are the two shapes the register has to handle: something that
 * is a step in the tree, and something that hangs off one and may hang off
 * several at once.
 */
final class DemoContentTypes implements ContentTypeProvider
{
    public const string CATEGORY = 'demo.category';

    public const string PRODUCT = 'demo.product';

    /** @return list<ContentType> */
    public function contentTypes(): array
    {
        return [
            new ContentType(self::CATEGORY, DemoModule::PAGE, 'category'),
            new ContentType(self::PRODUCT, DemoModule::PAGE, 'product'),
        ];
    }
}
