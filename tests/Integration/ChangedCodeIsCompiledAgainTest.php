<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;

/**
 * A suite on a working copy is handed a container compiled from the code it
 * runs, and not from the code that was there when the container was compiled.
 *
 * The framework rebuilds a cached container in two cases only. Outside debug
 * mode never: it hands back whatever was compiled under the same static
 * parameters, which is right on a server deployed with an empty cache and on a
 * working copy means a production-mode build keeps serving a constructor that
 * has since changed. In debug mode it compares the modification times of the
 * files the compilation used - but it records those times after compiling,
 * while the code it compiled was whatever the process had loaded before. A
 * process that loaded the old code and compiles after the files have changed,
 * which is a suite running across a merge, therefore writes a container of the
 * old code beside the new times, and every later process takes it as current.
 *
 * Each case compiles in a process of its own, because PHP cannot load two
 * versions of one class into one process, and against a copy of this
 * checkout's source in a temporary directory, so that the change the case
 * makes is never made to the code the rest of the suite is running. The copy's
 * classes are put ahead of the checkout's on the autoloader, and the process
 * then starts the way the suite does, through tests/bootstrap.php.
 *
 * The change is one service added by CoreExtension: code, not configuration,
 * because a change to a NEON file already reaches the cache key through
 * Trilobit\Core\Bootstrap::configurationHash().
 */
#[CoversNothing]
final class ChangedCodeIsCompiledAgainTest extends TestCase
{
    private const string EXTENSION = '/src/Core/DI/CoreExtension.php';

    private const string ANCHOR = "    public function loadConfiguration(): void\n    {\n";

    private const string PROBE = "        \$this->getContainerBuilder()->addDefinition('staleProbe')->setFactory(\\ArrayObject::class);\n";

    /** Long enough for a compilation on a loaded machine; a hung child fails the case rather than the run. */
    private const int SECONDS = 120;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trilobit-changed-code-' . bin2hex(random_bytes(6));
        $root = Bootstrap::rootDirectory();
        FileSystem::copy($root . '/src', $this->copy() . '/src');
        foreach (['common.neon', 'services.neon'] as $file) {
            FileSystem::copy($root . '/config/' . $file, $this->copy() . '/config/' . $file);
        }
    }

    protected function tearDown(): void
    {
        FileSystem::delete($this->directory);
    }

    public function testAProductionBuildIsCompiledAgainOnceTheCodeHasChanged(): void
    {
        $first = $this->build('prod');
        self::assertSame('no probe', $first['probe'], 'the copy already has the change the case is about to make');

        // Unchanged code has to find the container it compiled, or every
        // process in the suite would compile every build again.
        self::assertSame($first['file'], $this->build('prod')['file'], 'unchanged code was compiled again');

        $this->change();

        self::assertSame('probe', $this->build('prod')['probe'], 'the build is the container of the code before the change');
    }

    public function testABuildCompiledByAProcessThatSpansTheChangeIsNotTakenAsCurrent(): void
    {
        $spanning = $this->start('dev', wait: true);
        $this->waitFor($this->directory . '/loaded');
        $this->change();
        FileSystem::write($this->directory . '/go', '');

        // The precondition: the spanning process really wrote a container of
        // the old code. Without it the case below would pass for a reason
        // that has nothing to do with the cache.
        self::assertSame('no probe', $this->finish($spanning)['probe'], 'the spanning process compiled the new code');

        self::assertSame('probe', $this->build('dev')['probe'], 'a fresh process was handed the container of the old code');
    }

    private function copy(): string
    {
        return $this->directory . '/checkout';
    }

    private function change(): void
    {
        $file = $this->copy() . self::EXTENSION;
        $code = str_replace(self::ANCHOR, self::ANCHOR . self::PROBE, FileSystem::read($file), $count);
        self::assertSame(1, $count, 'CoreExtension::loadConfiguration() no longer opens the way this case expects');
        FileSystem::write($file, $code);
    }

    /** @return array{probe: string, file: string} */
    private function build(string $mode): array
    {
        return $this->finish($this->start($mode, wait: false));
    }

    /** @return array{resource, array<int, resource>} */
    private function start(string $mode, bool $wait): array
    {
        $script = $this->directory . '/build-' . bin2hex(random_bytes(4)) . '.php';
        FileSystem::write($script, sprintf(
            <<<'PHP'
                <?php

                declare(strict_types=1);

                $loader = require %1$s;
                $loader->addPsr4('Trilobit\\', %2$s . '/src/', true);
                require %3$s;

                if (%6$s) {
                    // The code is loaded now, before the change; it is only
                    // compiled once the case says the change has been made.
                    class_exists(\Trilobit\Core\DI\CoreExtension::class);
                    touch(%5$s . '/loaded');
                    for ($i = 0; !is_file(%5$s . '/go'); $i++) {
                        if ($i > 10 * %7$d) {
                            throw new \RuntimeException('The case never said the change was made.');
                        }
                        usleep(100_000);
                    }
                }

                $container = \Trilobit\Tests\Boot::container(
                    \Trilobit\Core\Module\ModuleList::of([], %2$s),
                    environment: \Trilobit\Core\Config\Environment::fromValues(['TRILOBIT_ENV' => %4$s]),
                    tempDirectory: %5$s . '/tmp',
                );

                echo json_encode([
                    'probe' => $container->hasService('staleProbe') ? 'probe' : 'no probe',
                    'file' => (new \ReflectionClass($container))->getFileName(),
                ]);

                PHP,
            var_export(Bootstrap::rootDirectory() . '/vendor/autoload.php', true),
            var_export($this->copy(), true),
            var_export(Bootstrap::rootDirectory() . '/tests/bootstrap.php', true),
            var_export($mode, true),
            var_export($this->directory, true),
            var_export($wait, true),
            self::SECONDS,
        ));

        $process = proc_open(['timeout', (string) self::SECONDS, PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param array{resource, array<int, resource>} $started
     * @return array{probe: string, file: string}
     */
    private function finish(array $started): array
    {
        [$process, $pipes] = $started;
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        self::assertSame(0, $code, sprintf("The build stopped with %d.\n%s\n%s", $code, $out, $err));
        $result = json_decode($out, true);
        self::assertIsArray($result, sprintf("The build said something other than its result.\n%s\n%s", $out, $err));
        self::assertIsString($result['probe'] ?? null);
        self::assertIsString($result['file'] ?? null);

        return ['probe' => $result['probe'], 'file' => $result['file']];
    }

    private function waitFor(string $file): void
    {
        for ($i = 0; !is_file($file); $i++) {
            self::assertLessThan(10 * self::SECONDS, $i, 'the spanning process never loaded the code');
            usleep(100_000);
        }
    }
}
