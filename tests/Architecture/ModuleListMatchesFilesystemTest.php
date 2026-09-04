<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Module\ModuleList;

/**
 * The configuration and the source tree have to agree about which modules
 * exist.
 *
 * They can drift in both directions and both are quiet failures: a name in
 * config/modules.neon with no directory behind it kills the boot only when
 * somebody switches it on, and a directory nobody declared is a module that
 * can never be switched on at all. Neither shows up in any other check.
 *
 * Core is not in the list and cannot be: it is always enabled, so a flag for
 * it would be a flag that has to be true.
 */
final class ModuleListMatchesFilesystemTest extends TestCase
{
    public function testEveryDeclaredModuleHasADirectoryAndEveryDirectoryIsDeclared(): void
    {
        $root = $this->root();

        self::assertSame($this->declaredIn($root), $this->onDiskIn($root));
    }

    public function testEveryDeclaredModuleBringsAnExtensionClassTheBootCanInstantiate(): void
    {
        $root = $this->root();

        foreach (ModuleList::fromNeon($root . '/config/modules.neon', $root)->names() as $name) {
            $file = sprintf('%s/src/%s/DI/%sExtension.php', $root, ucfirst($name), ucfirst($name));

            self::assertFileExists($file);
        }
    }

    /**
     * The detector, run against a tree that is deliberately out of step, so
     * that a detector looking in the wrong place fails here rather than
     * reporting agreement it never checked.
     */
    public function testTheDetectorSeesADirectoryNobodyDeclared(): void
    {
        $root = sys_get_temp_dir() . '/trilobit-modulelist-' . bin2hex(random_bytes(6));
        FileSystem::write($root . '/config/modules.neon', "parameters:\n    trilobit:\n        modules:\n            shop: true\n");
        FileSystem::write($root . '/src/Core/DI/CoreExtension.php', "<?php\n");
        FileSystem::write($root . '/src/Shop/DI/ShopExtension.php', "<?php\n");
        FileSystem::write($root . '/src/Widget/DI/WidgetExtension.php', "<?php\n");

        try {
            self::assertSame(['shop'], $this->declaredIn($root));
            self::assertSame(['shop', 'widget'], $this->onDiskIn($root));
        } finally {
            FileSystem::delete($root);
        }
    }

    /** @return list<string> */
    private function declaredIn(string $root): array
    {
        return ModuleList::fromNeon($root . '/config/modules.neon', $root)->names();
    }

    /**
     * A directory under src/ counts as a module when it carries the extension
     * class the boot would look for, which is the same rule the boot uses.
     *
     * @return list<string>
     */
    private function onDiskIn(string $root): array
    {
        $found = [];

        foreach ((array) scandir($root . '/src') as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..' || $entry === 'Core') {
                continue;
            }

            if (is_file(sprintf('%s/src/%s/DI/%sExtension.php', $root, $entry, $entry))) {
                $found[] = lcfirst($entry);
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
