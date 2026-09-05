<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Front;

use Trilobit\Core\Presentation\Front\ContentTemplate;

/** What the stand-in module's pages render with. */
final class DemoDefaultTemplate extends ContentTemplate
{
    public string $heading = '';

    public string $contentId = '';

    public string $contentPath = '';
}
