<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Event\ListenerCollection;

#[CoversClass(ListenerCollection::class)]
final class ListenerCollectionTest extends TestCase
{
    public function testACollectionWithoutListenersIsEmpty(): void
    {
        self::assertSame([], new ListenerCollection([])->all());
    }

    public function testTheListenersAreHandedBackInTheOrderTheyWereRegistered(): void
    {
        $first = new \stdClass();
        $second = new \stdClass();

        self::assertSame([$first, $second], new ListenerCollection([$first, $second])->all());
    }

    public function testAGeneratorIsWalkedOnlyOnce(): void
    {
        $calls = 0;
        $listeners = (static function () use (&$calls): \Generator {
            $calls++;

            yield new \stdClass();
        })();

        $collection = new ListenerCollection($listeners);
        $collection->all();
        $collection->all();

        self::assertSame(1, $calls);
    }
}
