<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\DI\Fixtures;

use Psr\EventDispatcher\ListenerProviderInterface;

/** Satisfies Dispatcher's own constructor dependency; see DispatcherFallbackTest. */
final class EmptyListenerProvider implements ListenerProviderInterface
{
    /** @return array<never> */
    public function getListenersForEvent(object $event): iterable
    {
        return [];
    }
}
