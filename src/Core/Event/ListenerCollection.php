<?php

declare(strict_types=1);

namespace Trilobit\Core\Event;

/**
 * The listeners the enabled modules contributed, gathered from the tag
 * Trilobit\Core\DI\CoreExtension::TagEventListener.
 *
 * It is deliberately only the collection, not a dispatcher: the dispatcher
 * arrives with the ports and events it is meant to carry. What has to exist
 * from the first commit is the place a module hands its listener to, so that
 * no module ever reaches for a dispatcher directly.
 *
 * The services are walked once and remembered, because the container hands
 * them over lazily and the same collection is asked more than once per request.
 */
final class ListenerCollection
{
    /** @var list<object>|null */
    private ?array $listeners = null;

    /** @param iterable<object> $services */
    public function __construct(
        private readonly iterable $services,
    ) {}

    /** @return list<object> */
    public function all(): array
    {
        if ($this->listeners !== null) {
            return $this->listeners;
        }

        $listeners = [];
        foreach ($this->services as $service) {
            $listeners[] = $service;
        }

        return $this->listeners = $listeners;
    }
}
