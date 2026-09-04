<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;

/**
 * The console entry point, run the way a person or a deployment script runs
 * it: as a program, from a shell, with an exit code.
 *
 * Calling the command class directly would prove the class works and leave the
 * part that actually breaks untested - the shebang, the autoloader path, the
 * container being built in console mode at all.
 */
#[CoversNothing]
final class WarmupCommandTest extends TestCase
{
    public function testWarmingUpWritesTheManifestOfThisCheckout(): void
    {
        $root = Bootstrap::rootDirectory();
        FileSystem::delete($root . '/var/build');

        $result = $this->console('app:warmup');

        self::assertSame(0, $result['code'], $result['output']);
        self::assertFileExists($root . '/var/build/modules.json');
        self::assertFileExists($root . '/var/build/sources.css');

        /** @var array{modules: list<array{name: string}>} $manifest */
        $manifest = json_decode(FileSystem::read($root . '/var/build/modules.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(
            ModuleList::fromNeon($root . '/config/modules.neon', $root)->enabledNames(),
            array_map(static fn(array $module): string => $module['name'], $manifest['modules']),
        );
    }

    public function testTheCommandIsListedByTheConsoleItself(): void
    {
        $result = $this->console('list');

        self::assertSame(0, $result['code'], $result['output']);
        self::assertStringContainsString('app:warmup', $result['output']);
    }

    /** @return array{code: int, output: string} */
    private function console(string $command): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, Bootstrap::rootDirectory() . '/bin/trilobit', $command, '--no-ansi'],
            $descriptors,
            $pipes,
            Bootstrap::rootDirectory(),
        );
        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'output' => $output];
    }
}
