<?php

declare(strict_types=1);

namespace Trilobit\Tests\Combination;

use Dom\HTMLDocument;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Tests\Boot;

/**
 * `composer test` runs without Node and without a real `npm run build`, so it
 * must not need www/build to exist - that is what tests/Boot.php's manifest
 * fixture is for. A test asserting that claim only from a machine that
 * happens to have a real www/build lying around cannot tell "the fixture
 * wiring works" from "the fixture is not even being read, and ViteMapper
 * quietly fell through to the real file that was there anyway" - which is
 * exactly the failure this project's own CI hit once: green here, red in a
 * clean checkout.
 *
 * So this test does not merely render a page; it renames the real www/build
 * out of the way first, if there is one, and puts it back afterwards whether
 * the test passes or not. That is the only way "an environment that never
 * had one" can be asserted rather than assumed.
 */
#[CoversNothing]
final class NoRealBuildRequiredTest extends TestCase
{
    private ?string $hiddenBuild = null;

    protected function tearDown(): void
    {
        if ($this->hiddenBuild !== null) {
            FileSystem::rename($this->hiddenBuild, $this->buildDirectory(), overwrite: true);
            $this->hiddenBuild = null;
        }
    }

    public function testRenderingEveryPageNeedsNoRealBuildDirectory(): void
    {
        $build = $this->buildDirectory();
        if (is_dir($build)) {
            $this->hiddenBuild = $build . '.hidden-by-' . self::class;
            FileSystem::rename($build, $this->hiddenBuild, overwrite: true);
        }

        self::assertDirectoryDoesNotExist($build, 'the real build directory is still here; this test proves nothing');

        $modules = ModuleList::of(['cms' => true, 'crm' => true, 'shop' => true], Bootstrap::rootDirectory());
        $container = Boot::container($modules);

        $home = HTMLDocument::createFromString(Build::render($container, 'Core:Front:Home'), LIBXML_NOERROR);
        self::assertNotNull($home->querySelector('[data-testid="layout"]'));

        foreach (Build::SWITCHABLE as $module) {
            $document = HTMLDocument::createFromString(
                Build::render($container, ucfirst($module) . ':Front:Status'),
                LIBXML_NOERROR,
            );
            self::assertNotNull(
                $document->querySelector('[data-testid="layout"]'),
                sprintf('%s did not render without a real www/build', $module),
            );
        }
    }

    private function buildDirectory(): string
    {
        return Bootstrap::rootDirectory() . '/www/build';
    }
}
