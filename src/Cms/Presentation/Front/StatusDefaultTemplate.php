<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Front;

use Trilobit\Core\Presentation\Front\FrontTemplate;

/**
 * What Cms:Front:Status:default renders with.
 */
final class StatusDefaultTemplate extends FrontTemplate
{
    public string $moduleName = '';

    public string $summary = '';
}
