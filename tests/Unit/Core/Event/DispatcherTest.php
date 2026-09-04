<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\ListenerProviderInterface;
use Trilobit\Core\Event\Dispatcher;
use Trilobit\Tests\Unit\Core\Event\Fixtures\FirstEvent;
use Trilobit\Tests\Unit\Core\Event\Fixtures\RecordingListener;

#[CoversClass(Dispatcher::class)]
final class DispatcherTest extends TestCase
{
    public function testEveryListenerTheProviderNamesIsCalledWithTheEvent(): void
    {
        $event = new FirstEvent();
        $first = new RecordingListener();
        $second = new RecordingListener();

        $provider = self::createStub(ListenerProviderInterface::class);
        $provider->method('getListenersForEvent')->willReturn([$first, $second]);

        $dispatcher = new Dispatcher($provider);
        $dispatcher->dispatch($event);

        self::assertSame([$event], $first->received);
        self::assertSame([$event], $second->received);
    }

    public function testTheEventItselfIsHandedBack(): void
    {
        $event = new FirstEvent();

        $provider = self::createStub(ListenerProviderInterface::class);
        $provider->method('getListenersForEvent')->willReturn([]);

        self::assertSame($event, new Dispatcher($provider)->dispatch($event));
    }

    public function testANonCallableListenerIsSkippedRatherThanFailing(): void
    {
        $provider = self::createStub(ListenerProviderInterface::class);
        $provider->method('getListenersForEvent')->willReturn([new \stdClass()]);

        new Dispatcher($provider)->dispatch(new FirstEvent());

        $this->expectNotToPerformAssertions();
    }
}
