<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Security;

use Trilobit\Core\Security\ResourceName;

/**
 * Resources a module should not be able to bring, one mistake each, so that a
 * suite can hand the structure exactly the one it is asking about.
 */
enum StrayResource: string implements ResourceName
{
    /** The name of one of Core's own, so that one name would mean two things. */
    case Content = 'app.administration.content';

    /** Under a resource no build has, so that a right on it would open nothing above it. */
    case Elsewhere = 'app.elsewhere.thing';
}
