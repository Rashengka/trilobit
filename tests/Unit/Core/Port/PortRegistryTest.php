<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Port;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Port\PortRegistry;

#[CoversClass(PortRegistry::class)]
final class PortRegistryTest extends TestCase
{
    public function testARegistryWithoutImplementationsIsEmpty(): void
    {
        $registry = new PortRegistry([]);

        self::assertSame([], $registry->all());
        self::assertFalse($registry->has(\Countable::class));
    }

    public function testAnImplementationIsFoundUnderTheInterfaceItWasRegisteredFor(): void
    {
        $implementation = new \ArrayObject([]);
        $registry = new PortRegistry([\Countable::class => $implementation]);

        self::assertTrue($registry->has(\Countable::class));
        self::assertSame($implementation, $registry->get(\Countable::class));
        self::assertSame([\Countable::class => $implementation], $registry->all());
    }

    public function testAskingForAPortNobodyImplementsSaysWhichOne(): void
    {
        $registry = new PortRegistry([]);

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage(\Countable::class);

        $registry->get(\Countable::class);
    }

    public function testAnImplementationThatDoesNotImplementThePortIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PortRegistry([\Countable::class => new \stdClass()]);
    }
}
