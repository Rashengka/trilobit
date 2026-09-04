<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Latte\Engine;
use Latte\Loaders\StringLoader;
use Nette\Assets\Registry;
use Nette\Assets\ViteMapper;
use Nette\Bridges\AssetsLatte\LatteExtension;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * .ai/plans/01b-frontend.md §5: production HTML never carries the dev
 * server's address, and dev HTML always carries its client script.
 *
 * The "scripts" block is read out of the real
 * src/Core/Presentation/Front/templates/@layout.latte, rather than retyped
 * here, so that a future edit to the {asset} tag is what this test exercises
 * and not a copy that has quietly drifted from it.
 *
 * It is deliberately not rendered through the real container against a real
 * `npm run build`. `composer test` runs inside the PHP container, which has
 * no Node, and a claim about production output has to hold without one - see
 * the fixture manifest below, built by hand from the shape Vite's own
 * manifest takes rather than by actually invoking Vite. What an actual build
 * produces is Combination/ManifestExclusionTest's job, run separately with
 * `npm run build`; a running `npm run dev` is not evidence for anything in
 * this suite either, on the same reasoning.
 */
#[CoversNothing]
final class ViteAssetRenderingTest extends TestCase
{
    private const string DEV_SERVER = 'http://localhost:5173';

    public function testProductionOutputNamesNoDevServer(): void
    {
        $html = $this->render($this->productionMapper());

        self::assertStringNotContainsString(self::DEV_SERVER, $html);
        self::assertStringNotContainsString('localhost:5173', $html);
        self::assertStringContainsString('app-', $html, 'the hashed production filename from the fixture manifest');
    }

    public function testDevelopmentOutputCarriesTheViteClient(): void
    {
        $html = $this->render($this->developmentMapper());

        // Latte HTML-escapes the '@' in an attribute value to &#64;, so the
        // literal substring to look for is what survives escaping.
        self::assertStringContainsString('vite/client', $html);
        self::assertStringContainsString(self::DEV_SERVER, $html);
    }

    private function render(ViteMapper $mapper): string
    {
        $registry = new Registry();
        $registry->addMapper('vite', $mapper);

        $engine = new Engine();
        $engine->addExtension(new LatteExtension($registry));
        $engine->setLoader(new StringLoader(['scripts' => $this->scriptsBlockSource()]));

        return $engine->renderToString('scripts');
    }

    /**
     * Extracted from the real layout file rather than copied, so that this
     * test tracks whatever the {asset} tag there actually says.
     */
    private function scriptsBlockSource(): string
    {
        $layout = dirname(__DIR__, 2) . '/src/Core/Presentation/Front/templates/@layout.latte';
        $source = FileSystem::read($layout);

        if (preg_match('/\{block scripts\}.*?\{\/block\}/s', $source, $match) !== 1) {
            self::fail(sprintf('%s has no "scripts" block to extract', $layout));
        }

        return $match[0];
    }

    private function productionMapper(): ViteMapper
    {
        $manifest = tempnam(sys_get_temp_dir(), 'trilobit-vite-manifest-') . '.json';
        FileSystem::write($manifest, json_encode([
            'assets/app.ts' => [
                'file' => 'app-fixture1234.js',
                'src' => 'assets/app.ts',
                'isEntry' => true,
                'css' => ['app-fixture5678.css'],
            ],
        ], JSON_THROW_ON_ERROR));

        return new ViteMapper(
            baseUrl: '/build',
            basePath: sys_get_temp_dir(),
            manifestPath: $manifest,
        );
    }

    private function developmentMapper(): ViteMapper
    {
        return new ViteMapper(
            baseUrl: '/build',
            basePath: sys_get_temp_dir(),
            devServer: self::DEV_SERVER,
        );
    }
}
