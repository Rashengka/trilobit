<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The rule the whole idea of a switchable module rests on, checked by running
 * the tool that enforces it against a tree that breaks it.
 *
 * `composer deptrac` already fails the gate on a module reaching into another
 * one, and the application contains no such reach - which is exactly why that
 * green result proves nothing on its own. A ruleset that had quietly stopped
 * forbidding it would look the same. So the project's own ruleset is run over
 * three classes written for the purpose, one of which imports another module's
 * class, and it has to report that one and only that one.
 *
 * The ruleset is the real file rather than a copy of it: only `paths` is
 * changed, to point at the tree written here instead of at the repository. If
 * somebody loosens a rule, this fails with it.
 */
#[CoversNothing]
final class NoModuleReachesIntoAnotherTest extends TestCase
{
    public function testAModuleImportingAnotherModulesClassIsAViolation(): void
    {
        $report = $this->analyse([
            'src/Core/Thing.php' => "<?php\n\nnamespace Trilobit\\Core;\n\nfinal class Thing {}\n",
            'src/Shop/Thing.php' => "<?php\n\nnamespace Trilobit\\Shop;\n\nfinal class Thing {}\n",
            'src/Cms/Reacher.php' => "<?php\n\nnamespace Trilobit\\Cms;\n\nuse Trilobit\\Shop\\Thing;\n\n"
                . "final class Reacher\n{\n    public function reach(): ?Thing\n    {\n        return null;\n    }\n}\n",
        ]);

        self::assertSame(1, $report['Violations'] ?? null, 'the ruleset no longer forbids one module reaching into another');
    }

    /**
     * The same tree without the import, so that a run reporting a violation
     * for some other reason - an uncovered class, a stray rule - is told apart
     * from one reporting this reach.
     */
    public function testTheSameTreeWithoutTheImportIsClean(): void
    {
        $report = $this->analyse([
            'src/Core/Thing.php' => "<?php\n\nnamespace Trilobit\\Core;\n\nfinal class Thing {}\n",
            'src/Shop/Thing.php' => "<?php\n\nnamespace Trilobit\\Shop;\n\nfinal class Thing {}\n",
            'src/Cms/Reacher.php' => "<?php\n\nnamespace Trilobit\\Cms;\n\nuse Trilobit\\Core\\Thing;\n\n"
                . "final class Reacher\n{\n    public function reach(): ?Thing\n    {\n        return null;\n    }\n}\n",
        ]);

        self::assertSame(0, $report['Violations'] ?? null);
        self::assertSame(0, $report['Uncovered'] ?? null);
    }

    /**
     * @param array<string, string> $tree file under the temporary root => its source
     * @return array<string, int>
     */
    private function analyse(array $tree): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/trilobit-deptrac-rules-' . bin2hex(random_bytes(6));

        $configuration = Yaml::parseFile($root . '/deptrac.yaml');
        self::assertIsArray($configuration);
        self::assertIsArray($configuration['deptrac'] ?? null);

        // The only change to the project's own ruleset: where to look. The
        // suites are not in this tree, so the layer collecting them would
        // report an uncovered path it cannot see.
        $configuration['deptrac']['paths'] = ['./src'];

        FileSystem::write($directory . '/deptrac.yaml', Yaml::dump($configuration, 8));
        foreach ($tree as $path => $source) {
            FileSystem::write($directory . '/' . $path, $source);
        }

        try {
            // Run the way the other suites that need a child process run one,
            // with proc_open rather than a component the project would then
            // have to depend on for this alone.
            $process = proc_open(
                [
                    PHP_BINARY,
                    $root . '/vendor/bin/deptrac',
                    'analyse',
                    '--config-file=' . $directory . '/deptrac.yaml',
                    '--cache-file=' . $directory . '/deptrac.cache',
                    '--no-progress',
                    '--no-ansi',
                    '--formatter=json',
                    '--output=' . $directory . '/report.json',
                    '--fail-on-uncovered',
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $directory,
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            self::assertFileExists($directory . '/report.json', 'deptrac wrote no report: ' . $output);

            $report = json_decode(FileSystem::read($directory . '/report.json'), true);
            self::assertIsArray($report);
            self::assertIsArray($report['Report'] ?? null);

            /** @var array<string, int> $summary */
            $summary = $report['Report'];

            return $summary;
        } finally {
            FileSystem::delete($directory);
        }
    }
}
