<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A rule nobody wrote does not exist.
 *
 * deptrac is run with --fail-on-uncovered, so a class in no layer already
 * fails the gate. What that flag cannot see is the other half of the same
 * mistake: a directory given a layer but left out of the ruleset. deptrac
 * treats a layer with no ruleset entry as a layer allowed to depend on
 * nothing, which happens to be the strictest possible rule, so it passes -
 * right up until the module has its first line of code and everybody
 * discovers the rule was never written down.
 *
 * This test therefore asserts both halves for every directory under src/, and
 * runs the same detector over a tree that is deliberately missing one, so that
 * a detector looking in the wrong place fails here rather than reporting a
 * coverage it never checked.
 */
final class DeptracCoversEverythingTest extends TestCase
{
    public function testEveryDirectoryUnderSourceHasALayerAndARule(): void
    {
        $root = $this->root();

        self::assertSame([], $this->uncoveredIn($root . '/deptrac.yaml', $root . '/src'));
    }

    public function testTheSourceTreeIsAmongTheAnalysedPaths(): void
    {
        $paths = $this->configuration($this->root() . '/deptrac.yaml')['paths'] ?? null;

        self::assertIsArray($paths);
        self::assertContains('./src', $paths);
    }

    public function testTheDetectorSeesADirectoryWithNoLayerAndOneWithNoRule(): void
    {
        $directory = sys_get_temp_dir() . '/trilobit-deptrac-' . bin2hex(random_bytes(6));
        FileSystem::write($directory . '/deptrac.yaml', <<<'YAML'
            deptrac:
              paths:
                - ./src
              layers:
                - name: Core
                  collectors:
                    - type: directory
                      value: src/Core/.*
                - name: Halfway
                  collectors:
                    - type: directory
                      value: src/Halfway/.*
              ruleset:
                Core: ~
            YAML);
        FileSystem::createDir($directory . '/src/Core');
        FileSystem::createDir($directory . '/src/Halfway');
        FileSystem::createDir($directory . '/src/Forgotten');

        try {
            self::assertSame(
                ['Forgotten' => 'no layer collects it', 'Halfway' => 'its layer Halfway has no ruleset entry'],
                $this->uncoveredIn($directory . '/deptrac.yaml', $directory . '/src'),
            );
        } finally {
            FileSystem::delete($directory);
        }
    }

    /**
     * @return array<string, string> directory name => what is missing, sorted by directory
     */
    private function uncoveredIn(string $configurationFile, string $sourceDirectory): array
    {
        $configuration = $this->configuration($configurationFile);

        $layers = $configuration['layers'] ?? null;
        self::assertIsArray($layers);

        /** @var array<string, string> $layerOfDirectory */
        $layerOfDirectory = [];
        foreach ($layers as $layer) {
            self::assertIsArray($layer);
            $name = $layer['name'] ?? null;
            self::assertIsString($name);

            $collectors = $layer['collectors'] ?? null;
            self::assertIsArray($collectors);

            foreach ($collectors as $collector) {
                self::assertIsArray($collector);
                if (($collector['type'] ?? null) !== 'directory') {
                    continue;
                }

                $value = $collector['value'] ?? null;
                self::assertIsString($value);
                if (preg_match('#^src/([A-Za-z0-9_]+)/#', $value, $match) === 1) {
                    $layerOfDirectory[$match[1]] = $name;
                }
            }
        }

        $ruleset = $configuration['ruleset'] ?? [];
        self::assertIsArray($ruleset);

        $uncovered = [];
        foreach ((array) scandir($sourceDirectory) as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..' || !is_dir($sourceDirectory . '/' . $entry)) {
                continue;
            }

            $layer = $layerOfDirectory[$entry] ?? null;
            if ($layer === null) {
                $uncovered[$entry] = 'no layer collects it';
            } elseif (!array_key_exists($layer, $ruleset)) {
                $uncovered[$entry] = sprintf('its layer %s has no ruleset entry', $layer);
            }
        }

        ksort($uncovered);

        return $uncovered;
    }

    /** @return array<string, mixed> */
    private function configuration(string $file): array
    {
        $parsed = Yaml::parseFile($file);
        self::assertIsArray($parsed);

        $deptrac = $parsed['deptrac'] ?? null;
        self::assertIsArray($deptrac);

        $configuration = [];
        foreach ($deptrac as $key => $value) {
            self::assertIsString($key);
            $configuration[$key] = $value;
        }

        return $configuration;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
