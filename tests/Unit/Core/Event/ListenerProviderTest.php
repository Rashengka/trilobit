<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Event\ListenerCollection;
use Trilobit\Core\Event\ListenerProvider;
use Trilobit\Tests\Unit\Core\Event\Fixtures\FirstEvent;
use Trilobit\Tests\Unit\Core\Event\Fixtures\RecordingListener;
use Trilobit\Tests\Unit\Core\Event\Fixtures\SecondEvent;

#[CoversClass(ListenerProvider::class)]
final class ListenerProviderTest extends TestCase
{
    public function testAListenerTypedToTheEventIsReturned(): void
    {
        $listener = new RecordingListener();
        $provider = new ListenerProvider(new ListenerCollection([$listener]));

        self::assertSame([$listener], iterator_to_array($provider->getListenersForEvent(new FirstEvent())));
    }

    public function testAListenerTypedToAnotherEventIsNotReturned(): void
    {
        $provider = new ListenerProvider(new ListenerCollection([new RecordingListener()]));

        self::assertSame([], iterator_to_array($provider->getListenersForEvent(new SecondEvent())));
    }

    public function testAnEmptyCollectionAnswersNothingForAnyEvent(): void
    {
        $provider = new ListenerProvider(new ListenerCollection([]));

        self::assertSame([], iterator_to_array($provider->getListenersForEvent(new FirstEvent())));
    }
}
