<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Front;

use Trilobit\Core\Contract\Content\ContentLink;
use Trilobit\Core\Presentation\Front\ContentTemplate;

/** What the stand-in modules' pages render with. */
final class DemoDefaultTemplate extends ContentTemplate
{
    public string $heading = '';

    public string $contentId = '';

    public string $contentPath = '';

    /** Null where nothing in this build could turn the stored reference into a link. */
    public ?ContentLink $related = null;
}
