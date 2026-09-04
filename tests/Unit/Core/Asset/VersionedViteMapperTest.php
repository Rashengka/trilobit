<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Asset;

use Nette\Assets\EntryAsset;
use Nette\Assets\HtmlRenderable;
use Nette\Assets\ScriptAsset;
use Nette\Assets\StyleAsset;
use Nette\Assets\ViteMapper;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Asset\VersionedViteMapper;

#[CoversClass(VersionedViteMapper::class)]
final class VersionedViteMapperTest extends TestCase
{
    private const string BASE_URL = 'https://example.com/build';

    private string $build = '';

    protected function setUp(): void
    {
        $this->build = sys_get_temp_dir() . '/trilobit-versioned-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        FileSystem::delete($this->build);
    }

    public function testTheEntryUrlCarriesTheVersionFromTheVersionFile(): void
    {
        $this->givenBuild(['app.js' => 'aaaa1111', 'app.css' => 'bbbb2222']);

        $asset = $this->mapper()->getAsset('assets/app.ts');

        self::assertInstanceOf(EntryAsset::class, $asset);
        self::assertSame(self::BASE_URL . '/app.js?v=aaaa1111', $asset->url);
    }

    /**
     * The stylesheet is the half of an entry point that is easiest to forget:
     * it is not a chunk of its own in the Vite manifest, only a string in the
     * entry's "css" list, so a version taken per chunk would leave every
     * stylesheet in the application on the browser's old copy.
     */
    public function testTheStylesheetOfAnEntryPointIsVersionedToo(): void
    {
        $this->givenBuild(['app.js' => 'aaaa1111', 'app.css' => 'bbbb2222']);

        $asset = $this->mapper()->getAsset('assets/app.ts');

        self::assertInstanceOf(EntryAsset::class, $asset);
        $styles = array_values(array_filter($asset->imports, static fn(HtmlRenderable $import): bool => $import instanceof StyleAsset));
        self::assertCount(1, $styles);
        self::assertSame(self::BASE_URL . '/app.css?v=bbbb2222', $styles[0]->url);
    }

    public function testAnEntryPointWithoutAStylesheetIsVersionedAsAPlainScript(): void
    {
        $this->givenBuild(['app.js' => 'aaaa1111', 'shop.js' => 'cccc3333']);

        $asset = $this->mapper()->getAsset('src/Shop/assets/entry.ts');

        self::assertInstanceOf(ScriptAsset::class, $asset);
        self::assertSame(self::BASE_URL . '/shop.js?v=cccc3333', $asset->url);
    }

    /**
     * The version is a cache-busting hint, not a part of the address, so its
     * absence has to leave a working page behind. This is not indulgence: the
     * suites render against a fixture manifest with no build directory next to
     * it at all (tests/Boot.php), and so does a checkout where somebody ran
     * Vite by hand. A missing manifest stays a hard error - that one really
     * does mean the page cannot be drawn.
     */
    public function testAMissingVersionFileLeavesTheUrlAlone(): void
    {
        $this->givenBuild(null);

        $asset = $this->mapper()->getAsset('assets/app.ts');

        self::assertInstanceOf(EntryAsset::class, $asset);
        self::assertSame(self::BASE_URL . '/app.js', $asset->url);
    }

    public function testAFileTheVersionFileDoesNotMentionIsLeftAlone(): void
    {
        $this->givenBuild(['app.css' => 'bbbb2222']);

        $asset = $this->mapper()->getAsset('assets/app.ts');

        self::assertInstanceOf(EntryAsset::class, $asset);
        self::assertSame(self::BASE_URL . '/app.js', $asset->url);
    }

    /**
     * A malformed file is the one case that is not tolerated. An absent file
     * means "nobody versioned this build"; a broken one means the step that
     * writes it ran and produced nonsense, and that is worth a sentence rather
     * than silently unversioned pages.
     */
    public function testAMalformedVersionFileIsRefused(): void
    {
        $this->givenBuild(['app.js' => 'aaaa1111']);
        FileSystem::write($this->build . '/.vite/versions.json', '{ not json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('versions.json');

        $this->mapper()->getAsset('assets/app.ts');
    }

    /**
     * With a dev server running, the mapper answers with the dev server's own
     * address and Vite handles freshness itself. Nothing there is under the
     * build's base URL, so nothing there is versioned - not even when a stale
     * versions.json from an earlier production build is still lying about.
     */
    public function testDevelopmentUrlsAreLeftAlone(): void
    {
        $this->givenBuild(['app.js' => 'aaaa1111']);

        $mapper = new VersionedViteMapper(new ViteMapper(
            baseUrl: self::BASE_URL,
            basePath: $this->build,
            manifestPath: $this->build . '/.vite/manifest.json',
            devServer: 'http://localhost:5173',
        ));

        $asset = $mapper->getAsset('assets/app.ts');

        self::assertInstanceOf(EntryAsset::class, $asset);
        self::assertSame('http://localhost:5173/assets/app.ts', $asset->url);
    }

    private function mapper(): VersionedViteMapper
    {
        return new VersionedViteMapper(new ViteMapper(
            baseUrl: self::BASE_URL,
            basePath: $this->build,
            manifestPath: $this->build . '/.vite/manifest.json',
        ));
    }

    /**
     * A build directory in the shape `npm run build` leaves behind: the
     * manifest Vite writes, and next to it the version file the post-build
     * step writes.
     *
     * @param array<string, string>|null $versions null writes no version file at all
     */
    private function givenBuild(?array $versions): void
    {
        FileSystem::write($this->build . '/.vite/manifest.json', json_encode([
            'assets/app.ts' => [
                'file' => 'app.js',
                'src' => 'assets/app.ts',
                'isEntry' => true,
                'css' => ['app.css'],
            ],
            'src/Shop/assets/entry.ts' => [
                'file' => 'shop.js',
                'src' => 'src/Shop/assets/entry.ts',
                'isEntry' => true,
            ],
        ], JSON_THROW_ON_ERROR));

        if ($versions !== null) {
            FileSystem::write($this->build . '/.vite/versions.json', json_encode($versions, JSON_THROW_ON_ERROR));
        }
    }
}
