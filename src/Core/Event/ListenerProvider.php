<?php

declare(strict_types=1);

namespace Trilobit\Core\Event;

use Psr\EventDispatcher\ListenerProviderInterface;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Which of the collected listeners answer to a given event, decided by the
 * type its own __invoke() declares.
 *
 * A listener is an ordinary object rather than a closure so that it can be a
 * tagged, autowired service; the type its __invoke() accepts is therefore the
 * only place left to say which event it wants, and reflection is the only way
 * to read that back. This runs once per dispatch rather than once per
 * request, and the listener list this project ships with is a handful of
 * entries, so the cost of asking is not worth caching against.
 */
final readonly class ListenerProvider implements ListenerProviderInterface
{
    public function __construct(
        private ListenerCollection $listeners,
    ) {}

    /**
     * @return iterable<object> every one of them invokable - Dispatcher is
     *     what actually checks, since a bare object is as far as reflection
     *     alone can promise.
     */
    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->listeners->all() as $listener) {
            if ($this->accepts($listener, $event)) {
                yield $listener;
            }
        }
    }

    private function accepts(object $listener, object $event): bool
    {
        $parameters = new ReflectionMethod($listener, '__invoke')->getParameters();
        $type = $parameters === [] ? null : $parameters[0]->getType();

        return $type instanceof ReflectionNamedType && $event instanceof ($type->getName());
    }
}
