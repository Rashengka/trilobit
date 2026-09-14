<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Security;

use Trilobit\Core\Security\ResourceName;

/**
 * A resource named by a number, which the interface cannot refuse on its own:
 * it extends BackedEnum, and an enum backed by an integer is one of those too.
 */
enum NumberedResource: int implements ResourceName
{
    case Ledger = 7;
}
