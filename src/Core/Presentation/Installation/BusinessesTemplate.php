<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Installation;

use Trilobit\Core\Presentation\Admin\AdminTemplate;

/**
 * What Core:Installation:Businesses:default renders with.
 */
final class BusinessesTemplate extends AdminTemplate
{
    public string $headline = '';

    public string $lead = '';

    /** @var list<BusinessSummary> */
    public array $businesses = [];

    /** The way back to the signpost of this section, which is where the page was reached from. */
    public string $sectionUrl = '';
}
