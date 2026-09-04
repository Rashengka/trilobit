#!/usr/bin/env node
//
// build-versions - writes down what each built file's contents hash to.
//
//   node bin/build-versions.mjs [outDir]      (default: www/build)
//
// It runs after `vite build` and writes <outDir>/.vite/versions.json, an
// object of built file name to a short hash of that file's contents.
//
// Why it exists: the build emits stable names - app.js, not app-0btNOuvg.js -
// because the output is committed, and a hashed name is not a modification to
// git but the deletion of one file and the arrival of another. The hash was
// also what told a browser the file had changed, though, so it moves into the
// query string: Trilobit\Core\Asset\VersionedViteMapper reads this file and
// renders `app.js?v=1a2b3c4d`. Git never sees the query string, so the file
// keeps its history and the packer keeps its delta.
//
// The file is written even when nothing changed, and its contents depend on
// nothing but the bytes of the build, so two builds of the same sources
// produce the same file - which is what lets CI compare a rebuild against the
// committed output. See bin/check-build-drift.mjs.
//

import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join, posix, relative, sep } from 'node:path';

/** Vite's own bookkeeping, and where this file goes. Nothing in it is versioned. */
const BOOKKEEPING = '.vite';

const VERSION_FILE = `${BOOKKEEPING}/versions.json`;

// Long enough that two different files colliding is not worth thinking about,
// short enough to read in a URL. It is a cache key, not a signature.
const LENGTH = 8;

/** Every file under `directory`, as paths relative to it, excluding the bookkeeping directory. */
function builtFiles(directory, prefix = '') {
    const files = [];

    for (const entry of readdirSync(join(directory, prefix), { withFileTypes: true })) {
        const path = prefix === '' ? entry.name : posix.join(prefix, entry.name);

        if (entry.isDirectory()) {
            if (path !== BOOKKEEPING) {
                files.push(...builtFiles(directory, path));
            }
        } else if (entry.isFile()) {
            files.push(path);
        }
    }

    return files;
}

function version(path) {
    return createHash('sha256').update(readFileSync(path)).digest('hex').slice(0, LENGTH);
}

const outDir = process.argv[2] ?? join('www', 'build');

let files;
try {
    files = builtFiles(outDir);
} catch (error) {
    process.stderr.write(
        `${outDir} cannot be read, so there is nothing to version. Run "npm run build" first.\n${error.message}\n`,
    );
    process.exit(1);
}

// Sorted, so that the file is a function of the build and not of the order the
// filesystem happened to hand the entries back in.
const versions = {};
for (const file of files.sort()) {
    versions[file] = version(join(outDir, file.split(posix.sep).join(sep)));
}

mkdirSync(join(outDir, BOOKKEEPING), { recursive: true });
writeFileSync(join(outDir, VERSION_FILE), `${JSON.stringify(versions, null, 2)}\n`);

process.stdout.write(
    `${relative(process.cwd(), join(outDir, VERSION_FILE)) || VERSION_FILE}  ${files.length} file(s)\n`,
);
