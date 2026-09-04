<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front\Signpost;

/**
 * A module contributes a homepage entry point by registering a service that
 * implements this and carries the tag
 * Trilobit\Core\DI\CoreExtension::TAG_SIGNPOST_PROVIDER.
 */
interface SignpostProvider
{
    /** @return iterable<Signpost> */
    public function provide(): iterable;
}
