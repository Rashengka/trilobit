import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import type { Plugin } from 'vite';

/** Written next to the bundles, under a name that does not move - see vite.config.ts. */
const FILE_NAME = 'licenses.txt';

// The spellings a licence file is shipped under. Matched case-insensitively
// and with any extension, so LICENSE, License.md, licence.txt and COPYING all
// count.
const LICENSE_FILE = /^(licen[cs]e|copying)(\.[a-z]+)?$/i;

interface BundledPackage {
    readonly name: string;
    readonly version: string;
    readonly license: string;
    readonly text: string;
}

/**
 * The directory of the package a module belongs to, or null for the project's
 * own code.
 *
 * Read off the path after the last node_modules segment rather than by walking
 * up to the nearest package.json: a package may carry package.json files of its
 * own below its root - `dist/esm/package.json` saying `"type": "module"` - and
 * those are not packages. The last segment is what makes a package installed
 * inside another's node_modules its own package, and a scope takes one segment
 * more.
 */
function packageDirectory(id: string): string | null {
    const path = id.replace(/\\/g, '/').split('?')[0];
    const marker = '/node_modules/';
    const at = path.lastIndexOf(marker);
    if (at === -1) {
        return null;
    }

    const segments = path.slice(at + marker.length).split('/');
    const length = segments[0].startsWith('@') ? 2 : 1;

    return path.slice(0, at + marker.length) + segments.slice(0, length).join('/');
}

function readPackage(directory: string): BundledPackage | string {
    const manifest = JSON.parse(readFileSync(join(directory, 'package.json'), 'utf8')) as {
        name?: unknown;
        version?: unknown;
        license?: unknown;
    };

    if (typeof manifest.name !== 'string' || manifest.name === '') {
        return `${directory}/package.json has no name, so the package it holds cannot be listed.`;
    }

    const label = `${manifest.name} ${String(manifest.version)}`;

    if (typeof manifest.license !== 'string' || manifest.license === '') {
        return `${label} declares no "license" in its package.json.`;
    }

    // Sorted, so that a package shipping two spellings gives the same answer
    // on every filesystem.
    const file = readdirSync(directory).filter((entry) => LICENSE_FILE.test(entry)).sort()[0];
    if (file === undefined) {
        return `${label} has no licence file (LICENSE, LICENCE or COPYING) in its package.`;
    }

    return {
        name: manifest.name,
        version: String(manifest.version),
        license: manifest.license,
        // Line endings are the packer's, not the author's; they are normalised
        // so that two machines write the same bytes.
        text: readFileSync(join(directory, file), 'utf8').replace(/\r\n?/g, '\n').trim(),
    };
}

// `@import` and `@plugin` copy a package's contents into the output - a
// stylesheet inlined, or the rules a plugin generates. `@reference` only
// borrows declarations and emits nothing, so it is not matched, and an
// `@import` marked `reference` is skipped below for the same reason. Both the
// quoted and the url() form are matched, the latter with or without quotes.
const STYLESHEET_PULL = /@(?:import|plugin)\s+(?:url\(\s*(["']?)([^"')\s]+)\1\s*\)|(["'])([^"']+)\3)([^;]*);/g;

/** The package a bare specifier names: `@scope/name/theme.css` is `@scope/name`. */
function packageName(specifier: string): string {
    const segments = specifier.split('/');

    return segments.slice(0, segments[0].startsWith('@') ? 2 : 1).join('/');
}

/** Node's own lookup: the nearest node_modules above the importing file that holds the package. */
function installedPackage(name: string, importer: string): string | null {
    for (let directory = dirname(importer); ; directory = dirname(directory)) {
        const candidate = join(directory, 'node_modules', name);
        if (existsSync(join(candidate, 'package.json'))) {
            return candidate;
        }
        if (dirname(directory) === directory) {
            return null;
        }
    }
}

/**
 * Adds to `found` the directory of every package a stylesheet copies in through
 * `@import` or `@plugin`, following the stylesheets it imports in turn.
 *
 * Needed because a CSS import never reaches the bundler as a module: Tailwind -
 * and Vite's own CSS pipeline - inline it themselves, so `@import "tailwindcss"`
 * puts Tailwind into app.css without Tailwind being a module of any chunk. The
 * source is read from disk rather than taken from the transform pipeline, which
 * by the time it reaches a plugin has already inlined the imports this has to
 * find.
 *
 * Whatever cannot be followed fails rather than being skipped, for the same
 * reason a package without a licence does.
 */
function stylesheetPackages(file: string, seen: Set<string>, found: Set<string>): void {
    if (seen.has(file)) {
        return;
    }
    seen.add(file);

    const source = readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
    for (const match of source.matchAll(STYLESHEET_PULL)) {
        const specifier = match[2] ?? match[4];
        const conditions = match[5];

        // Borrowed declarations, or an address the browser fetches by itself:
        // either way nothing is copied into the build.
        if (/\breference\b/.test(conditions) || /^[a-z][a-z0-9+.-]*:/i.test(specifier) || specifier.startsWith('/')) {
            continue;
        }

        if (specifier.startsWith('.')) {
            const imported = resolve(dirname(file), specifier);
            if (!existsSync(imported)) {
                throw new Error(`${file} imports ${specifier}, which is not there, so what it pulls in cannot be listed.`);
            }
            stylesheetPackages(imported, seen, found);
            continue;
        }

        const name = packageName(specifier);
        const directory = installedPackage(name, file);
        if (directory === null) {
            throw new Error(`${file} imports ${specifier}, and no package called ${name} is installed above it, so its licence cannot be listed.`);
        }
        found.add(directory);

        // The package's own stylesheet may import another package's, so it is
        // followed too: the file a subpath names, or the one its "style" field
        // points at, which is what a bare `@import "tailwindcss"` loads.
        const manifest = JSON.parse(readFileSync(join(directory, 'package.json'), 'utf8')) as { style?: unknown };
        const subpath = specifier.slice(name.length + 1);
        const entry = subpath !== '' ? subpath : typeof manifest.style === 'string' ? manifest.style : null;
        if (entry !== null && entry.endsWith('.css') && existsSync(join(directory, entry))) {
            stylesheetPackages(join(directory, entry), seen, found);
        }
    }
}

/**
 * Writes the licence of every third-party package the build bundled into
 * www/build/licenses.txt.
 *
 * Derived from the modules of the emitted chunks - what was actually copied -
 * rather than from package.json, which lists what was asked for: a package
 * bundled transitively is not in it, and one nobody imports any more still is.
 * Every module of a chunk counts, including those that rendered no JavaScript:
 * a stylesheet imported from a package is such a module, and its contents end
 * up in a .css file instead. And every stylesheet among those modules is read
 * for the packages it imports itself, which no chunk shows - see
 * stylesheetPackages().
 *
 * A tool that only runs during the build - Vite, the Tailwind plugin, the CSS
 * minifier - leaves none of its own code in the output and is not listed.
 *
 * A package with no licence file or no "license" field fails the build. Leaving
 * it out would produce a file that looks complete, and the whole point of the
 * file is that nobody has to check it by hand.
 */
export function bundledLicenses(): Plugin {
    return {
        name: 'trilobit:bundled-licenses',
        apply: 'build',

        generateBundle(_options, bundle) {
            const directories = new Set<string>();
            const stylesheets = new Set<string>();
            for (const output of Object.values(bundle)) {
                if (output.type !== 'chunk') {
                    continue;
                }
                for (const id of Object.keys(output.modules)) {
                    // A virtual module is the bundler's own, not a package's.
                    if (id.startsWith('\0')) {
                        continue;
                    }

                    const directory = packageDirectory(id);
                    if (directory !== null) {
                        directories.add(directory);
                    }

                    const path = id.split('?')[0];
                    if (path.endsWith('.css')) {
                        stylesheetPackages(path, stylesheets, directories);
                    }
                }
            }

            const packages = new Map<string, BundledPackage>();
            for (const directory of directories) {
                const read = readPackage(directory);
                if (typeof read === 'string') {
                    this.error(`${read} Its code is bundled into the build, which is published, so its licence has to be shipped with it.`);
                }
                packages.set(`${read.name}@${read.version}`, read);
            }

            const sorted = [...packages.values()].sort(
                (a, b) => a.name.localeCompare(b.name, 'en') || a.version.localeCompare(b.version, 'en'),
            );

            const rule = '='.repeat(80);
            const sections = sorted.map((pkg) => [
                rule,
                `Package: ${pkg.name} ${pkg.version}`,
                `License: ${pkg.license}`,
                '',
                pkg.text,
                '',
            ].join('\n'));

            this.emitFile({
                type: 'asset',
                fileName: FILE_NAME,
                source: [
                    'Third-party packages bundled into the files in this directory, and the licence each',
                    'is distributed under. Written by the build from the modules it bundled.',
                    '',
                    ...sections,
                ].join('\n'),
            });
        },
    };
}
