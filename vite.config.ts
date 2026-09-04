import { readFileSync } from 'node:fs';
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

// The shared entry point, plus one per enabled module. A disabled module has
// no line in var/build/modules.json, so its entry point never reaches this
// array and therefore never reaches rollupOptions.input or the manifest that
// comes out of the build - see tests/frontend/manifest.test.mjs.
const entries = [
    'assets/app.ts',
    ...modules.modules.map((module) => `${module.directory}/assets/entry.ts`),
];

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
    },
    plugins: [
        nette({ entry: entries }),
        tailwindcss(),
    ],
});
