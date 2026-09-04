#!/usr/bin/env node
//
// check-build-drift - refuses a www/build that no longer matches the sources.
//
//   node bin/check-build-drift.mjs        (or: npm run check:build)
//
// Exit codes:
//   0  the committed build is what the current sources produce
//   1  it is not; rebuild with `npm run build`
//   2  the rebuild could not be made at all
//
// Why it exists: www/build is committed, which trades one silent failure for
// another. Nothing stops somebody editing a .ts, forgetting to rebuild, and
// committing - the repository then shows new source while the application runs
// old code, and every check is green, because every check reads the same stale
// file the application does. So the build is made again here and compared.
//
// It compares the *output*, never the source. A change to a source file that
// the bundler drops - an exported function nobody calls, which tree-shaking
// removes - produces the same bytes and needs no rebuild, and this passes it in
// silence. That is the correct answer and not a hole: what is committed is the
// output, so the output is what can be stale.
//
// The rebuild is written to a throwaway directory. Nothing here touches
// www/build, so running this while a dev server or a browser is reading that
// directory is safe.
//

import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, posix, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('..', import.meta.url));
const BUILD_DIR = join(ROOT, 'www', 'build');

/**
 * Written by @nette/vite-plugin while `npm run dev` runs, and left behind when
 * it crashes. It is not part of a build and not in the repository, so a
 * comparison that noticed it would fail on a developer's machine for a reason
 * that has nothing to do with drift.
 */
const NOT_PART_OF_THE_BUILD = ['.vite/nette.json'];

/** Every file under `directory`, as paths relative to it and separated by '/'. */
function filesIn(directory, prefix = '') {
    const files = [];

    for (const entry of readdirSync(join(directory, prefix), { withFileTypes: true })) {
        const path = prefix === '' ? entry.name : posix.join(prefix, entry.name);

        if (entry.isDirectory()) {
            files.push(...filesIn(directory, path));
        } else if (entry.isFile() && !NOT_PART_OF_THE_BUILD.includes(path)) {
            files.push(path);
        }
    }

    return files.sort();
}

function contents(directory, file) {
    return readFileSync(join(directory, file.split(posix.sep).join(sep)));
}

function rebuildInto(outDir) {
    const run = (command, args) => execFileSync(command, args, {
        cwd: ROOT,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    run(join(ROOT, 'node_modules', '.bin', 'vite'), ['build', '--outDir', outDir, '--emptyOutDir']);
    run(process.execPath, [join(ROOT, 'bin', 'build-versions.mjs'), outDir]);
}

function fail(message) {
    process.stderr.write(`${message}\n`);
    process.exit(1);
}

const rebuilt = mkdtempSync(join(tmpdir(), 'trilobit-build-drift-'));

try {
    let committed;
    try {
        committed = filesIn(BUILD_DIR);
    } catch {
        fail(
            'www/build is not there at all, and it is committed, so it should be.\n'
            + 'Run "npm run build" and commit what it writes.',
        );
    }

    try {
        rebuildInto(rebuilt);
    } catch (error) {
        process.stderr.write(
            'The frontend could not be rebuilt, so nothing could be compared.\n'
            + `${error.stderr ?? error.message}\n`,
        );
        process.exit(2);
    }

    const fresh = filesIn(rebuilt);

    const missing = fresh.filter((file) => !committed.includes(file));
    const extra = committed.filter((file) => !fresh.includes(file));
    const changed = fresh
        .filter((file) => committed.includes(file))
        .filter((file) => !contents(BUILD_DIR, file).equals(contents(rebuilt, file)));

    if (missing.length > 0 || extra.length > 0 || changed.length > 0) {
        const lines = [
            'www/build is not what the current sources build.',
            '',
            ...changed.map((file) => `  changed:  www/build/${file}`),
            ...missing.map((file) => `  missing:  www/build/${file}`),
            ...extra.map((file) => `  no longer built:  www/build/${file}`),
            '',
            'The built frontend is committed, so it has to be rebuilt with the change that made it stale:',
            '',
            '    php bin/trilobit app:warmup && npm run build',
            '',
            'then commit www/build along with the sources.',
        ];

        fail(lines.join('\n'));
    }

    process.stdout.write(`www/build matches the sources (${committed.length} file(s)).\n`);
} finally {
    rmSync(rebuilt, { recursive: true, force: true });
}
