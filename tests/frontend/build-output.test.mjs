// What a build is allowed to leave behind, now that www/build is committed.
//
// Two claims, and they only make sense together. The first is that no built
// file carries a content hash in its name: a hashed name is not a modification
// to git but the deletion of one file and the arrival of another, so the
// history of the file and the delta the packer would have stored are both
// lost. The second is that something still tells a browser the file changed -
// bin/build-versions.mjs, which writes the hash into a file the PHP side turns
// into `?v=`.
//
// Like manifest.test.mjs this runs under Node's own runner rather than
// PHPUnit, and writes to a throwaway --outDir rather than to www/build.
//
// Run with: npm run test:frontend

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../..', import.meta.url));
const VITE_BIN = join(ROOT, 'node_modules', '.bin', 'vite');
const VERSIONS_SCRIPT = join(ROOT, 'bin', 'build-versions.mjs');

/** The module list every build below is made against, written out as app:warmup would. */
const MODULES = {
    modules: [
        { name: 'cms', namespace: 'Trilobit\\Cms', directory: 'src/Cms' },
        { name: 'crm', namespace: 'Trilobit\\Crm', directory: 'src/Crm' },
        { name: 'shop', namespace: 'Trilobit\\Shop', directory: 'src/Shop' },
    ],
};

function buildInto(outDir) {
    // Deliberately not inside outDir: the version file below names everything
    // the build emitted, and a fixture lying among the output would be
    // indistinguishable from something Vite wrote.
    const modulesFile = join(mkdtempSync(join(tmpdir(), 'trilobit-modules-')), 'modules.json');
    writeFileSync(modulesFile, JSON.stringify(MODULES));

    execFileSync(VITE_BIN, ['build', '--outDir', outDir], {
        cwd: ROOT,
        env: { ...process.env, TRILOBIT_MODULES_FILE: modulesFile },
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    execFileSync(process.execPath, [VERSIONS_SCRIPT, outDir], {
        cwd: ROOT,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    return outDir;
}

/** Everything the build emitted, excluding its own bookkeeping directory. */
function emittedFiles(outDir) {
    return readdirSync(outDir, { withFileTypes: true })
        .filter((entry) => entry.isFile())
        .map((entry) => entry.name)
        .sort();
}

test('every entry point is emitted under a name that does not move', () => {
    const outDir = buildInto(mkdtempSync(join(tmpdir(), 'trilobit-stable-names-')));

    const manifest = JSON.parse(readFileSync(join(outDir, '.vite', 'manifest.json'), 'utf8'));

    assert.equal(manifest['assets/app.ts'].file, 'app.js');
    assert.equal(manifest['src/Cms/assets/entry.ts'].file, 'cms.js');
    assert.equal(manifest['src/Crm/assets/entry.ts'].file, 'crm.js');
    assert.equal(manifest['src/Shop/assets/entry.ts'].file, 'shop.js');
    assert.deepEqual(manifest['assets/app.ts'].css, ['app.css']);

    // The claim above is about entry points; this one is about everything
    // else the build emits, so that a chunk or an asset cannot quietly bring a
    // hash back in.
    for (const file of emittedFiles(outDir)) {
        assert.doesNotMatch(file, /-[A-Za-z0-9_-]{8}\./, `${file} carries a content hash in its name`);
    }

    rmSync(outDir, { recursive: true, force: true });
});

test('the version file names every emitted file and holds the hash of its contents', () => {
    const outDir = buildInto(mkdtempSync(join(tmpdir(), 'trilobit-versions-')));

    const versions = JSON.parse(readFileSync(join(outDir, '.vite', 'versions.json'), 'utf8'));

    assert.deepEqual(Object.keys(versions).sort(), emittedFiles(outDir));

    for (const [file, version] of Object.entries(versions)) {
        const expected = createHash('sha256').update(readFileSync(join(outDir, file))).digest('hex');
        assert.equal(version, expected.slice(0, version.length), `${file} is not versioned by its own contents`);
        assert.match(version, /^[0-9a-f]{8}$/);
    }

    rmSync(outDir, { recursive: true, force: true });
});

test('two builds of the same sources produce the same versions', () => {
    const first = buildInto(mkdtempSync(join(tmpdir(), 'trilobit-versions-first-')));
    const second = buildInto(mkdtempSync(join(tmpdir(), 'trilobit-versions-second-')));

    const read = (dir) => readFileSync(join(dir, '.vite', 'versions.json'), 'utf8');

    assert.equal(read(first), read(second));

    rmSync(first, { recursive: true, force: true });
    rmSync(second, { recursive: true, force: true });
});
