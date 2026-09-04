<?php

declare(strict_types=1);

namespace Trilobit\Core\Event;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Core's own PSR-14 dispatcher: the psr/event-dispatcher package supplies
 * only the interfaces, and carrying an event to the listeners that accept it
 * is short enough to write once rather than to pull in a library for; see
 * .ai/plans/01a-komunikace-modulu.md §5 for why contributte/event-dispatcher
 * was rejected.
 *
 * This is deliberately not the mechanism a module reaches for to talk to
 * another module - a port is, because a typed interface is visible to
 * PHPStan and a string event name is not (§1 of the same document). What this
 * class carries is Core's own cross-cutting concerns: the audit trail today,
 * cache invalidation or outgoing mail later. Nothing outside src/Core/ is
 * meant to construct or call it, which deptrac.yaml enforces with a layer of
 * its own - the point where a module reached for this directly is exactly the
 * point it should have reached for a port instead.
 */
final readonly class Dispatcher implements EventDispatcherInterface
{
    public function __construct(
        private ListenerProviderInterface $listeners,
    ) {}

    public function dispatch(object $event): object
    {
        foreach ($this->listeners->getListenersForEvent($event) as $listener) {
            if (!is_callable($listener)) {
                continue;
            }

            $listener($event);
        }

        return $event;
    }
}
