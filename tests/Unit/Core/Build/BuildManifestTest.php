<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Build;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Build\BuildManifest;
use Trilobit\Core\Module\ModuleList;

/**
 * What a build writes down about itself.
 *
 * PHP learns which modules are on by compiling a container; everything else -
 * the asset bundler, the stylesheet that has to know which templates to read -
 * learns it from these two files. They are generated rather than committed,
 * because a committed copy is a second source of truth that goes stale the
 * first time somebody switches a module off.
 */
#[CoversClass(BuildManifest::class)]
final class BuildManifestTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/trilobit-build-' . bin2hex(random_bytes(6));
        FileSystem::createDir($this->root);
    }

    protected function tearDown(): void
    {
        FileSystem::delete($this->root);
    }

    public function testTheManifestListsExactlyTheEnabledModules(): void
    {
        $manifest = $this->manifest(['shop' => true, 'cms' => false, 'crm' => true]);

        /** @var array{modules: list<array{name: string, namespace: string, directory: string}>} $decoded */
        $decoded = json_decode($manifest->modulesJson(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(
            [
                ['name' => 'crm', 'namespace' => 'Trilobit\Crm', 'directory' => 'src/Crm'],
                ['name' => 'shop', 'namespace' => 'Trilobit\Shop', 'directory' => 'src/Shop'],
            ],
            $decoded['modules'],
        );
    }

    public function testAModuleThatIsSwitchedOffIsNowhereInTheManifest(): void
    {
        $json = $this->manifest(['shop' => true, 'cms' => false, 'crm' => false])->modulesJson();

        self::assertStringNotContainsString('cms', $json);
        self::assertStringNotContainsString('Crm', $json);
    }

    /**
     * The stylesheet is what tells the CSS build which templates to read. A
     * switched-off module left in it would put that module's classes into the
     * bundle of a build that cannot render them.
     */
    public function testTheStylesheetPointsAtCoreAndAtTheEnabledModulesOnly(): void
    {
        $css = $this->manifest(['shop' => true, 'cms' => false, 'crm' => false])->sourcesCss();

        self::assertStringContainsString('@source "../../src/Core";', $css);
        self::assertStringContainsString('@source "../../src/Shop";', $css);
        self::assertStringNotContainsString('Cms', $css);
        self::assertStringNotContainsString('Crm', $css);
    }

    public function testWritingPutsBothFilesUnderTheBuildDirectoryAndSaysWhereTheyWent(): void
    {
        $written = $this->manifest(['shop' => true, 'cms' => true, 'crm' => true])->write();

        self::assertSame(
            [
                $this->root . '/var/build/modules.json',
                $this->root . '/var/build/sources.css',
            ],
            $written,
        );
        self::assertFileExists($written[0]);
        self::assertFileExists($written[1]);
    }

    public function testWritingTwiceLeavesTheSameFiles(): void
    {
        $manifest = $this->manifest(['shop' => true, 'cms' => false, 'crm' => false]);
        $manifest->write();
        $first = FileSystem::read($this->root . '/var/build/modules.json');

        $manifest->write();

        self::assertSame($first, FileSystem::read($this->root . '/var/build/modules.json'));
    }

    /** @param array<string, bool> $modules */
    private function manifest(array $modules): BuildManifest
    {
        return new BuildManifest(ModuleList::of($modules, $this->root));
    }
}
