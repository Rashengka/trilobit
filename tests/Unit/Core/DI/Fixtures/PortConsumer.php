<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\DI\Fixtures;

/** Takes the port as a plain constructor dependency, the way a module would; see PortFallbackTest. */
final readonly class PortConsumer
{
    public function __construct(
        public TestPort $port,
    ) {}
}
