<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Module\ModuleList;

/**
 * Core may not name a module, not even in a comment.
 *
 * deptrac already stops Core from depending on one, but a dependency is not
 * the only way the knowledge gets in: a service name built from a module's
 * name, a branch on one being enabled, a to-do mentioning the module it is
 * waiting for. Each of those is a place somebody has to edit when a fourth
 * module is written, and a place no compiler will point at.
 *
 * Two spellings are looked for, and only two: the module's class name as a
 * word - `Shop`, never `e-shop` - and its configuration key in quotes -
 * `'shop'`. Those are the shapes a module's name takes in code. Prose is left
 * alone on purpose: a page describing what the application is may well say
 * "e-shop, CRM and CMS" without Core knowing that any of the three exists as
 * a directory.
 *
 * The names come from config/modules.neon rather than from a list written
 * here, so that a module added tomorrow is covered by this test today.
 */
final class CoreKnowsNoModuleTest extends TestCase
{
    public function testNoModuleIsNamedAnywhereUnderCore(): void
    {
        $root = $this->root();
        $names = ModuleList::fromNeon($root . '/config/modules.neon', $root)->names();

        self::assertNotSame([], $names, 'with no module declared this test would assert nothing');
        self::assertSame([], $this->mentionsUnder($root . '/src/Core', $names));
    }

    /**
     * The detector against files that do name a module, so that a detector
     * matching nothing fails here instead of reporting agreement it never
     * checked - and against prose, so that it stays a check on code.
     */
    public function testTheDetectorTellsCodeFromProse(): void
    {
        $directory = sys_get_temp_dir() . '/trilobit-corementions-' . bin2hex(random_bytes(6));
        FileSystem::write($directory . '/Prose.php', "<?php\n// an e-shop, a CRM and a CMS in one.\n");
        FileSystem::write($directory . '/Import.php', "<?php\nuse Trilobit\\Shop\\Domain\\Thing;\n");
        FileSystem::write($directory . '/Key.php', "<?php\n\$enabled = \$modules['shop'] ?? false;\n");

        try {
            self::assertSame(['Import.php', 'Key.php'], $this->mentionsUnder($directory, ['cms', 'crm', 'shop']));
        } finally {
            FileSystem::delete($directory);
        }
    }

    /**
     * @param list<string> $names
     * @return list<string> paths relative to $directory, sorted
     */
    private function mentionsUnder(string $directory, array $names): array
    {
        $spellings = [];
        foreach ($names as $name) {
            $spellings[] = '\b' . preg_quote(ucfirst($name), '#') . '\b';
            $spellings[] = '[\'"]' . preg_quote($name, '#') . '[\'"]';
        }

        $pattern = '#' . implode('|', $spellings) . '#';
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            if (!$file->isFile()) {
                continue;
            }

            if (preg_match($pattern, FileSystem::read($file->getPathname())) === 1) {
                $found[] = substr($file->getPathname(), strlen($directory) + 1);
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
