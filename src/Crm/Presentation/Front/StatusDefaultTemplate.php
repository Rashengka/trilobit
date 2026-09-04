<?php

declare(strict_types=1);

namespace Trilobit\Crm\Presentation\Front;

use Trilobit\Core\Presentation\Front\FrontTemplate;

/**
 * What Crm:Front:Status:default renders with.
 */
final class StatusDefaultTemplate extends FrontTemplate
{
    public string $moduleName = '';

    public string $summary = '';
}
