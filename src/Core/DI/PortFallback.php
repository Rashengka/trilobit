<?php

declare(strict_types=1);

namespace Trilobit\Core\DI;

use Nette\DI\ContainerBuilder;

/**
 * Registers, for every port with nothing behind it yet, a service of that
 * exact type backed by a fallback class - so a constructor asking for the
 * port by type always gets an answer, whether or not any enabled module
 * implements it. See .ai/plans/01a-komunikace-modulu.md §2.
 *
 * A separate class rather than a private method of CoreExtension because the
 * claim worth testing on its own is narrower than "Core compiles": that
 * without this call, a service depending on an unimplemented port fails to
 * compile, and with it, the same service compiles and receives the fallback.
 * Trilobit\Tests\Unit\Core\DI\PortFallbackTest proves both directions against
 * a bare container of its own, the way DeptracCoversEverythingTest proves its
 * detector against a tree built for the purpose rather than against
 * production configuration.
 */
final class PortFallback
{
    /** @param array<class-string, class-string> $ports port interface => the class to fall back to */
    public static function register(ContainerBuilder $builder, array $ports, string $prefix, string $tag): void
    {
        foreach ($ports as $port => $fallback) {
            if ($builder->getByType($port) !== null) {
                continue;
            }

            $builder->addDefinition($prefix . '.' . str_replace('\\', '_', $port))
                ->setType($port)
                ->setFactory($fallback)
                ->addTag($tag, $port);
        }
    }
}
