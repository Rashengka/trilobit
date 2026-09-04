<?php

declare(strict_types=1);

namespace Trilobit\Core\Asset;

use Nette\Assets\Asset;
use Nette\Assets\EntryAsset;
use Nette\Assets\HtmlRenderable;
use Nette\Assets\Mapper;
use Nette\Assets\ScriptAsset;
use Nette\Assets\StyleAsset;
use Nette\Assets\ViteMapper;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Nette\Utils\JsonException;

/**
 * Puts the cache-busting back that stable file names took away.
 *
 * The built frontend is committed (see the README), and it is committed under
 * names that do not move: `app.js`, not `app-0btNOuvg.js`. A hashed name is
 * not a modification to git but the deletion of one file and the arrival of
 * another, which costs the history of the file, the delta the packer would
 * otherwise store, and a manifest that changes on every build.
 *
 * What the hashed name was also doing, though, was telling browsers that the
 * file had changed. Nothing else did: Nette\Assets\ViteMapper builds a URL as
 * baseUrl . '/' . file and adds nothing of its own. So the name is stable and
 * the version moves into the query string instead - `app.js?v=1a2b3c4d`, the
 * hash of the file's own contents, written next to the Vite manifest by the
 * post-build step in bin/build-versions.mjs.
 *
 * Two properties of a query-string version are worth stating, because they are
 * why this is a decorator and not a change to the build:
 *
 *   - It is not part of the address. A request for `app.js` without it is the
 *     same request, so nothing breaks when the version is missing - it only
 *     means a browser may hold an old copy longer. Everything below therefore
 *     falls through to the plain URL rather than failing, with one exception
 *     named at readVersions().
 *   - It is per file. The stylesheet of an entry point is not a chunk in the
 *     Vite manifest, only a string in that entry's "css" list, so a version
 *     recorded per chunk would never reach it. The version file is keyed by
 *     built file name, which covers both.
 */
final class VersionedViteMapper implements Mapper
{
    /** Written by bin/build-versions.mjs, next to the manifest Vite writes. */
    public const string VERSION_FILE = '.vite/versions.json';

    /** The query-string parameter, chosen to match what the rest of the world writes. */
    private const string PARAMETER = 'v';

    /** @var array<string, string>|null built file name => version; null until first read */
    private ?array $versions = null;

    public function __construct(
        private readonly ViteMapper $inner,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function getAsset(string $reference, array $options = []): Asset
    {
        $asset = $this->inner->getAsset($reference, $options);

        // EntryAsset extends ScriptAsset, so it has to be asked about first.
        // Anything else - an image or a font reached through the manifest -
        // keeps the URL it came with: the classes differ in what a rebuilt one
        // would need to be given, and a stale picture is not the problem a
        // stale bundle is.
        return match (true) {
            $asset instanceof EntryAsset => new EntryAsset(
                url: $this->versioned($asset->url),
                imports: array_map($this->versionedDependency(...), $asset->imports),
                preloads: array_map($this->versionedDependency(...), $asset->preloads),
                type: $asset->type,
                file: $asset->file,
                integrity: $asset->integrity,
                crossorigin: $asset->crossorigin,
            ),
            $asset instanceof ScriptAsset, $asset instanceof StyleAsset => $this->versionedDependency($asset),
            default => $asset,
        };
    }

    /**
     * The stylesheets and preloaded chunks an entry point carries. They are
     * typed as renderables rather than as assets, so they are rebuilt through
     * their own method instead of recursing through getAsset().
     */
    private function versionedDependency(HtmlRenderable $dependency): HtmlRenderable
    {
        return match (true) {
            $dependency instanceof StyleAsset => new StyleAsset(
                url: $this->versioned($dependency->url),
                file: $dependency->file,
                media: $dependency->media,
                integrity: $dependency->integrity,
                crossorigin: $dependency->crossorigin,
            ),
            $dependency instanceof ScriptAsset => new ScriptAsset(
                url: $this->versioned($dependency->url),
                file: $dependency->file,
                type: $dependency->type,
                integrity: $dependency->integrity,
                crossorigin: $dependency->crossorigin,
            ),
            default => $dependency,
        };
    }

    private function versioned(string $url): string
    {
        $file = $this->builtFile($url);
        $version = $file === null ? null : ($this->versions()[$file] ?? null);

        return $version === null
            ? $url
            : $url . (str_contains($url, '?') ? '&' : '?') . self::PARAMETER . '=' . rawurlencode($version);
    }

    /**
     * The name the version file would know this URL by, or null when the URL
     * does not name a file of this build at all - which is what a URL looks
     * like while `npm run dev` is running, and Vite keeps its own dev output
     * fresh without help.
     */
    private function builtFile(string $url): ?string
    {
        $prefix = $this->inner->getBaseUrl() . '/';

        return str_starts_with($url, $prefix)
            ? substr($url, strlen($prefix))
            : null;
    }

    /** @return array<string, string> */
    private function versions(): array
    {
        return $this->versions ??= $this->readVersions();
    }

    /**
     * An absent file means this build was never versioned, and that has to stay
     * survivable: the suites render against a fixture manifest with no build
     * directory beside it, and so does a checkout where somebody ran Vite by
     * hand. A file that is there but unreadable is the opposite case - the step
     * that writes it ran and produced nonsense - and that is worth saying out
     * loud rather than serving unversioned pages that look fine.
     *
     * @return array<string, string>
     */
    private function readVersions(): array
    {
        $path = $this->inner->getBasePath() . '/' . self::VERSION_FILE;
        if (!is_file($path)) {
            return [];
        }

        try {
            $decoded = Json::decode(FileSystem::read($path), forceArrays: true);
        } catch (JsonException $e) {
            throw new \RuntimeException(sprintf("Failed to read the asset versions from '%s'.", $path), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf("'%s' does not hold an object of file names and versions.", $path));
        }

        $versions = [];
        foreach ($decoded as $file => $version) {
            if (!is_string($file) || !is_string($version)) {
                throw new \RuntimeException(sprintf("'%s' does not hold an object of file names and versions.", $path));
            }

            $versions[$file] = $version;
        }

        return $versions;
    }
}
