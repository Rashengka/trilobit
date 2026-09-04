<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\DI\Fixtures;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Stands in for a module asking for Core's dispatcher the forbidden way: by
 * the PSR interface, which deptrac cannot see because that interface is
 * Vendor. See DispatcherFallbackTest.
 */
final readonly class DispatcherConsumer
{
    public function __construct(
        public EventDispatcherInterface $events,
    ) {}
}
