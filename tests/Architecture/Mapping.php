<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Nette\DI\Container;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Tests\Boot;

/**
 * The mapping the object-relational mapper ends up with, as a plain list a
 * rule can be run over.
 *
 * Two things are worth saying about how it is obtained. It is read from a
 * build with every declared module switched on, because a rule about where a
 * foreign key may point has to see the modules this checkout happens to have
 * switched off as well. And it is read without a database: the configuration
 * declares which server version the application is written against, so the
 * mapper knows the platform without asking one, and the mapping is therefore
 * readable on a machine that has no database running.
 */
final class Mapping
{
    /** @var list<ClassMetadata<object>>|null */
    private static ?array $application = null;

    private static ?Container $container = null;

    /**
     * Every entity of the application, from a build containing every module.
     *
     * @return list<ClassMetadata<object>>
     */
    public static function ofTheApplication(): array
    {
        if (self::$application !== null) {
            return self::$application;
        }

        return self::$application = self::sorted(
            self::container()->getByType(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata(),
        );
    }

    /**
     * Every entity whose source is in $directories, mapped the way the
     * application maps its own and over the same connection, so that no test
     * has to repeat which platform the project is written against.
     *
     * It exists so that a rule which reports nothing over the real mapping can
     * be shown to be a rule that looked: the same rule is run over a shape the
     * application deliberately does not contain.
     *
     * More than one directory is taken because a fixture that has to point at
     * a real entity - the tenant, say - can only be mapped alongside it; a
     * fixture pointing at a copy would be a rule tested against a shape the
     * application does not have.
     *
     * @return list<ClassMetadata<object>>
     */
    public static function inDirectory(string ...$directories): array
    {
        // Put together by hand rather than through ORMSetup, which insists on a
        // cache implementation this project has no other reason to depend on.
        $configuration = new Configuration();
        $configuration->setMetadataDriverImpl(new AttributeDriver(array_values($directories)));
        $configuration->setProxyDir(Bootstrap::rootDirectory() . '/var/tmp');
        $configuration->setProxyNamespace('Trilobit\Tests\Proxy');
        $configuration->enableNativeLazyObjects(true);

        return self::sorted(
            new EntityManager(self::container()->getByType(Connection::class), $configuration)
                ->getMetadataFactory()
                ->getAllMetadata(),
        );
    }

    private static function container(): Container
    {
        if (self::$container instanceof Container) {
            return self::$container;
        }

        $root = Bootstrap::rootDirectory();
        $declared = ModuleList::fromNeon($root . '/config/modules.neon', $root);

        return self::$container = Boot::container(
            ModuleList::of(array_fill_keys($declared->names(), true), $root),
        );
    }

    /**
     * @param array<int, ClassMetadata<object>> $metadata
     *
     * @return list<ClassMetadata<object>>
     */
    private static function sorted(array $metadata): array
    {
        usort($metadata, static fn(ClassMetadata $a, ClassMetadata $b): int => $a->getName() <=> $b->getName());

        return $metadata;
    }
}
