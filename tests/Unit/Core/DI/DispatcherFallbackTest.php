<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\DI;

use Nette\DI\ContainerBuilder;
use Nette\DI\ServiceCreationException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Trilobit\Core\Event\Dispatcher;
use Trilobit\Tests\Unit\Core\DI\Fixtures\DispatcherConsumer;
use Trilobit\Tests\Unit\Core\DI\Fixtures\EmptyListenerProvider;

/**
 * The hole deptrac cannot see, closed the other way: deptrac stops a module
 * from naming Trilobit\Core\Event\Dispatcher, but psr/event-dispatcher's
 * EventDispatcherInterface sits in the Vendor layer every module may depend
 * on, so a module asking for that interface by type - the way a module's own
 * constructor would - is invisible to deptrac entirely. The only fence left
 * is the container: the dispatcher has to be registered with autowiring off.
 *
 * Proven both ways, the way PortFallbackTest proves the port mechanism: left
 * autowired, a module-shaped consumer compiles against the interface, which
 * is the danger; with autowiring off, the same consumer fails to compile for
 * want of a service of that type - which is Trilobit\Core\DI\CoreExtension's
 * actual registration of 'dispatcher', reproduced here rather than read from
 * the extension itself, because CoreExtension cannot compile on its own
 * without the rest of the application around it.
 *
 * Trilobit\Tests\Integration\ApplicationSkeletonTest is the other half: it
 * asks the real, fully-compiled application container the same question and
 * is what actually catches a regression in CoreExtension's own registration,
 * where this file proves the mechanism such a regression would have broken.
 */
#[CoversNothing]
final class DispatcherFallbackTest extends TestCase
{
    public function testAnAutowiredDispatcherReachesAConsumerAskingForTheInterfaceByType(): void
    {
        $builder = $this->builderWithADispatcherConsumer();
        $builder->addDefinition('dispatcher')->setFactory(Dispatcher::class);

        $builder->resolve();
        $builder->complete();

        self::assertNotNull($builder->getByType(EventDispatcherInterface::class));
    }

    public function testANonAutowiredDispatcherFailsToReachTheSameConsumer(): void
    {
        $builder = $this->builderWithADispatcherConsumer();
        $builder->addDefinition('dispatcher')->setFactory(Dispatcher::class)->setAutowired(false);

        $this->expectException(ServiceCreationException::class);
        $this->expectExceptionMessage(EventDispatcherInterface::class);

        $builder->resolve();
        $builder->complete();
    }

    private function builderWithADispatcherConsumer(): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->addDefinition('listeners')->setFactory(EmptyListenerProvider::class);
        $builder->addDefinition('consumer')->setFactory(DispatcherConsumer::class);

        return $builder;
    }
}
