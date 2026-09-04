<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration;

use Nette\Assets\Registry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Asset\VersionedViteMapper;
use Trilobit\Core\DI\CoreExtension;
use Trilobit\Tests\Boot;

/**
 * The wiring, rather than the behaviour: Trilobit\Core\Asset\
 * VersionedViteMapper has its own suite, and none of it would ever run in a
 * page if the container handed the pages the mapper Nette registered instead.
 *
 * Worth a compiled container of its own because the decoration is done by
 * reaching into somebody else's extension - CoreExtension rewrites the
 * addMapper() call that Nette\Bridges\AssetsDI\DIExtension put on the
 * registry. A release that changes the shape of that call would leave the
 * pages working and unversioned, which is the kind of failure nothing else
 * here would notice.
 */
#[CoversClass(CoreExtension::class)]
final class AssetVersioningTest extends TestCase
{
    public function testThePagesGetTheVersioningMapperAndNotTheBareOne(): void
    {
        $registry = Boot::container()->getByType(Registry::class);

        $mapper = $registry->getMapper('vite');

        self::assertInstanceOf(VersionedViteMapper::class, $mapper);
    }
}
