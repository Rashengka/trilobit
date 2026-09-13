<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracy\Debugger;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;

/**
 * What a clone looks like before anybody has run anything in it.
 *
 * var/ holds only generated files, so it is not in the repository, and the
 * framework refuses to start without the directories inside it. This was found
 * by cloning into a temporary directory and watching the first test die; the
 * case below is the same situation without the clone.
 *
 * The clone is a directory of its own rather than this checkout with its var/
 * taken away. That var/ is not this test's to take: it holds the cache of the
 * server running from this checkout, its logs, and the cache the container's
 * composer is mounted on. The root under test carries only what the boot reads
 * before there is a container - the two shared configuration files - and no
 * module, so that nothing else has to be copied for it.
 */
#[CoversClass(Bootstrap::class)]
final class FreshCheckoutTest extends TestCase
{
    private string $root;

    private ?string $logDirectory;

    /** @var array<string, string> */
    private array $editorMapping;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/trilobit-fresh-checkout-' . bin2hex(random_bytes(6));
        foreach (['common.neon', 'services.neon'] as $file) {
            FileSystem::copy(Bootstrap::rootDirectory() . '/config/' . $file, $this->root . '/config/' . $file);
        }

        // The boot points Tracy at the root it was given. Left there, the next
        // test in this process would log into a directory tearDown() deletes.
        $this->logDirectory = Debugger::$logDirectory;
        $this->editorMapping = Debugger::$editorMapping;
    }

    protected function tearDown(): void
    {
        Debugger::$logDirectory = $this->logDirectory;
        Debugger::$editorMapping = $this->editorMapping;
        FileSystem::delete($this->root);
    }

    public function testBootingCreatesTheGeneratedDirectoriesAClonDoesNotHave(): void
    {
        self::assertDirectoryDoesNotExist($this->root . '/var');

        Bootstrap::configurator(ModuleList::of([], $this->root));

        self::assertDirectoryExists($this->root . '/var/log');
        self::assertDirectoryExists($this->root . '/var/tmp');
    }
}
