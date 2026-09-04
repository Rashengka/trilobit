<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\DI;

use Nette\DI\ContainerBuilder;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\ServiceCreationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\DI\PortFallback;
use Trilobit\Tests\Unit\Core\DI\Fixtures\NullTestPort;
use Trilobit\Tests\Unit\Core\DI\Fixtures\PortConsumer;
use Trilobit\Tests\Unit\Core\DI\Fixtures\TestPort;

/**
 * The claim CoreExtension::PORTS rests on, proven against a container built
 * for the purpose rather than the application's own: a service that takes a
 * port as an ordinary constructor dependency fails to compile when nothing
 * stands behind that port, and compiles - into the fallback - the moment
 * PortFallback::register() has run. This is what makes "the null
 * implementation is deregistered" a compile failure rather than a silently
 * wrong answer; see .ai/plans/01a-komunikace-modulu.md §2 and
 * Trilobit\Tests\Integration\ApplicationSkeletonTest for the same guarantee
 * seen through the real application container.
 */
#[CoversClass(PortFallback::class)]
final class PortFallbackTest extends TestCase
{
    public function testACallerOfAnUnimplementedPortFailsToCompile(): void
    {
        $builder = $this->builderWithAPortConsumer();

        $this->expectException(ServiceCreationException::class);
        $this->expectExceptionMessage(TestPort::class);

        $builder->resolve();
        $builder->complete();
    }

    public function testTheSameCallerCompilesOnceTheFallbackIsRegistered(): void
    {
        $builder = $this->builderWithAPortConsumer();

        PortFallback::register($builder, [TestPort::class => NullTestPort::class], 'port', 'test.port');

        self::assertNotNull($builder->getByType(TestPort::class));

        $builder->resolve();
        $builder->complete();

        $definition = $builder->getDefinition($builder->getByType(TestPort::class) ?? '');
        self::assertInstanceOf(ServiceDefinition::class, $definition);
        self::assertSame(NullTestPort::class, $definition->getFactory()->getEntity());
    }

    public function testAPortAlreadyImplementedKeepsItsOwnImplementationRatherThanTheFallback(): void
    {
        $builder = new ContainerBuilder();
        $builder->addDefinition('real')->setFactory(NullTestPort::class)->setType(TestPort::class);

        PortFallback::register($builder, [TestPort::class => NullTestPort::class], 'port', 'test.port');

        self::assertSame('real', $builder->getByType(TestPort::class));
        self::assertFalse($builder->hasDefinition('port.' . str_replace('\\', '_', TestPort::class)));
    }

    private function builderWithAPortConsumer(): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->addDefinition('consumer')->setFactory(PortConsumer::class);

        return $builder;
    }
}
