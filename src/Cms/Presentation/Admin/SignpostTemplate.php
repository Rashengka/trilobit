<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Admin;

use Trilobit\Core\Presentation\Admin\AdminTemplate;
use Trilobit\Core\Presentation\Component\SignpostLink;

/**
 * What Cms:Admin:Signpost:default renders with.
 */
final class SignpostTemplate extends AdminTemplate
{
    public string $headline = '';

    public string $lead = '';

    /** @var list<SignpostLink> */
    public array $items = [];
}
