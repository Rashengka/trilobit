<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;

/**
 * What a clone looks like before anybody has run anything in it.
 *
 * var/ holds only generated files, so it is not in the repository, and the
 * framework refuses to start without the directories inside it. This was found
 * by cloning into a temporary directory and watching the first test die; the
 * case below is the same situation without the clone.
 */
#[CoversClass(Bootstrap::class)]
final class FreshCheckoutTest extends TestCase
{
    public function testBootingCreatesTheGeneratedDirectoriesAClonDoesNotHave(): void
    {
        $root = Bootstrap::rootDirectory();

        FileSystem::delete($root . '/var');
        self::assertDirectoryDoesNotExist($root . '/var');

        Bootstrap::configurator();

        self::assertDirectoryExists($root . '/var/log');
        self::assertDirectoryExists($root . '/var/tmp');
    }
}
