import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { defineConfig } from 'vite';
import nette from '@nette/vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/**
 * The shape src/Core/Build/BuildManifest.php writes to var/build/modules.json:
 * one entry per enabled module, Core excluded because Core is not switchable.
 */
interface ModuleManifestEntry {
    readonly name: string;
    readonly namespace: string;
    /** Relative to the project root, e.g. "src/Shop". */
    readonly directory: string;
}

interface ModuleManifest {
    readonly modules: ModuleManifestEntry[];
}

// Overridable only so that tests/frontend/manifest.test.mjs can point this at
// a fixture without touching the real generated file - the application never
// sets this variable, so a real build always reads var/build/modules.json.
const MODULES_FILE = process.env.TRILOBIT_MODULES_FILE ?? 'var/build/modules.json';

/**
 * Reads which modules this build is made of. Thrown here rather than caught
 * and defaulted to "build everything", because a silent fallback would mean a
 * disabled module ends up in the bundle exactly when somebody forgets to run
 * the warmup command - and nobody would notice, because it would still work.
 */
function readModules(): ModuleManifest {
    let contents: string;
    try {
        contents = readFileSync(MODULES_FILE, 'utf8');
    } catch {
        throw new Error(
            `${MODULES_FILE} is missing. Run "php bin/trilobit app:warmup" first - `
            + 'the list of enabled modules is derived from config/modules.neon, '
            + 'and the asset build cannot parse NEON itself.',
        );
    }

    return JSON.parse(contents) as ModuleManifest;
}

const modules = readModules();

/**
 * The shared entry point, plus one per enabled module, each with the name its
 * bundle is written under. A disabled module has no line in
 * var/build/modules.json, so its entry point never reaches this map and
 * therefore never reaches rollupOptions.input or the manifest that comes out
 * of the build - see tests/frontend/manifest.test.mjs.
 *
 * Keyed by absolute path because that is what Rollup hands back as a chunk's
 * facadeModuleId, and that is the only way to tell the module entry points
 * apart: they are all called entry.ts, so the file name decides nothing.
 * `www/build/shop.js` is the module's name, not its file's.
 */
const entryNames = new Map<string, string>([
    [resolve('assets/app.ts'), 'app'],
    ...modules.modules.map(
        (module): [string, string] => [resolve(module.directory, 'assets/entry.ts'), module.name],
    ),
]);

// A module called "app" would silently take the shared bundle's name and one
// of the two would be lost. It cannot happen today - "app" is not a directory
// under src/ - but the collision would show up as a missing script rather than
// as an error, so it is refused here instead.
if (new Set(entryNames.values()).size !== entryNames.size) {
    throw new Error(
        `Two entry points want the same output name: ${[...entryNames.values()].join(', ')}. `
        + 'A module may not be called "app".',
    );
}

/**
 * The output name for one entry chunk.
 *
 * Nothing is guessed and nothing falls back to a hashed name: a build that
 * emits an entry this file has never heard of has stopped being the build this
 * project commits, and saying so is cheaper than finding an unexplained
 * file in www/build later.
 */
function entryFileName(facadeModuleId: string | null): string {
    const name = facadeModuleId === null ? undefined : entryNames.get(facadeModuleId);
    if (name === undefined) {
        throw new Error(`No stable output name is declared for the entry point ${facadeModuleId ?? '(unknown)'}.`);
    }

    return `${name}.js`;
}

export default defineConfig({
    // The plugin defaults this to "assets", which would make every path in
    // entries above - all of them written relative to the project root, to
    // match what BuildManifest and the manifest test expect - resolve from
    // the wrong place.
    root: '.',
    build: {
        // Matches config/common.neon's `assets: mapping: vite: path: build`;
        // nette/assets looks for the manifest under this directory's own
        // .vite/ subdirectory.
        outDir: 'www/build',

        // No content hash in any output name. The built files are committed
        // (see the README), and a hashed name is not a modification to git but
        // the deletion of one file and the arrival of another: the history of
        // the file is gone, the packer stores both copies whole rather than a
        // delta, and the manifest changes on every build. Telling a browser
        // that a file changed is done by bin/build-versions.mjs and
        // Trilobit\Core\Asset\VersionedViteMapper instead, which put the hash
        // in the query string, where git never sees it.
        rollupOptions: {
            output: {
                entryFileNames: (chunk) => entryFileName(chunk.facadeModuleId),
                chunkFileNames: '[name].js',
                assetFileNames: '[name][extname]',
            },
        },
    },
    plugins: [
        nette({ entry: [...entryNames.keys()] }),
        tailwindcss(),
    ],
});
