<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Resources;

use Trilobit\Core\Security\ResourceName;

/**
 * An enum of resources written and never brought - the way round the rule that
 * every question is predefined, which Trilobit\Tests\Architecture\EveryResourceEnumIsContributedTest
 * has to report.
 */
enum UncontributedResource: string implements ResourceName
{
    case Fixture = 'app.administration.fixture';
}
