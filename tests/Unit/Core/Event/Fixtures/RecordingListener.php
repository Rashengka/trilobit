<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Event\Fixtures;

/** A listener that only remembers what it was called with, typed to FirstEvent. */
final class RecordingListener
{
    /** @var list<FirstEvent> */
    public array $received = [];

    public function __invoke(FirstEvent $event): void
    {
        $this->received[] = $event;
    }
}
