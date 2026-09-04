<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\TestCase;

/**
 * Raising the bar back up is something nobody ever does, so lowering it has to
 * be impossible rather than discouraged. A baseline file is how every one of
 * the tools in the gate offers to lower it, and this test is the reason none
 * of them can.
 *
 * It also checks itself: the same detector is run over a directory that does
 * contain a baseline, so a detector that found nothing because it looks in the
 * wrong place fails here instead of passing quietly.
 */
final class NoBaselineTest extends TestCase
{
    /** File names by which each tool in the gate remembers what it agreed to ignore. */
    private const array BaselineNames = [
        'phpstan-baseline.neon',
        'phpstan-baseline.php',
        'phpstan-baseline.neon.dist',
        'deptrac-baseline.yaml',
        'deptrac-baseline.yml',
        '.php-cs-fixer-baseline.json',
        'rector-baseline.php',
        '.phpstorm.meta.php',
    ];

    public function testTheRepositoryHoldsNoBaseline(): void
    {
        self::assertSame([], $this->baselinesUnder($this->root()));
    }

    public function testTheDetectorFindsABaselineWhenThereIsOne(): void
    {
        $directory = sys_get_temp_dir() . '/trilobit-baseline-' . bin2hex(random_bytes(6));
        FileSystem::createDir($directory . '/nested');
        FileSystem::write($directory . '/nested/phpstan-baseline.neon', "parameters:\n");

        try {
            self::assertSame(['nested/phpstan-baseline.neon'], $this->baselinesUnder($directory));
        } finally {
            FileSystem::delete($directory);
        }
    }

    public function testStaticAnalysisRunsAtTheMaximumLevelAndIncludesNoBaseline(): void
    {
        $configuration = FileSystem::read($this->root() . '/phpstan.neon');

        self::assertMatchesRegularExpression('/^\s*level:\s*max\s*$/m', $configuration);
        self::assertStringNotContainsString('baseline', $this->withoutComments($configuration));
    }

    public function testTheLayerRulesIncludeNoBaseline(): void
    {
        $configuration = FileSystem::read($this->root() . '/deptrac.yaml');

        self::assertStringNotContainsString('baseline', $this->withoutComments($configuration));
    }

    /**
     * Comments are dropped before the check, because the reason a baseline is
     * forbidden is written in those very files and would otherwise read as the
     * thing it forbids.
     */
    private function withoutComments(string $configuration): string
    {
        return (string) preg_replace('/^\s*#.*$/m', '', $configuration);
    }

    /**
     * @return list<string> paths relative to $root, sorted
     */
    private function baselinesUnder(string $root): array
    {
        $skipped = ['vendor', 'node_modules', 'var', '.git', '.ai'];
        $found = [];

        $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            static fn(\SplFileInfo $file): bool => !$file->isDir() || !in_array($file->getFilename(), $skipped, true),
        );

        foreach (new \RecursiveIteratorIterator($filter) as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            if (in_array($file->getFilename(), self::BaselineNames, true)) {
                $found[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        sort($found);

        return $found;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
