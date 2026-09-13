<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Security;

use Trilobit\Core\Security\ResourceName;

/**
 * The resources a module of a suite's own brings, the way a real module brings
 * its own: an enum of its own, filed under the administration Core already
 * has, and a file of its own saying what may be asked of each
 * (demo-permissions.neon beside this).
 *
 * Two levels, so that a walk through what a module added that stopped at the
 * first one would be seen stopping.
 */
enum DemoResource: string implements ResourceName
{
    /** A section of the administration, and what the ledger falls under. */
    case Demo = 'app.administration.demo';

    case Ledger = 'app.administration.demo.ledger';
}
