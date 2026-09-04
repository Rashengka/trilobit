// vite.config.ts is what decides which module's entry point ends up in a
// build - how a switched-off module's code stays out of it. That is
// JavaScript reading a JSON file, not PHP, so it is tested here with Node's
// own test runner rather than PHPUnit: `composer test` runs inside the PHP
// container, which has no Node, and a claim that only holds when Node
// happens to be present would not be a claim at all.
//
// Every build below runs against a fixture var/build/modules.json, pointed
// at through TRILOBIT_MODULES_FILE (vite.config.ts falls back to the real
// path when it is unset), and writes to a throwaway --outDir - never to
// www/build, which a developer or another process may be relying on.
//
// Run with: npm run test:manifest

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../..', import.meta.url));
const VITE_BIN = join(ROOT, 'node_modules', '.bin', 'vite');

function tempDir(prefix) {
    return mkdtempSync(join(tmpdir(), prefix));
}

/** Runs a real `vite build` against a fixture module list, writing to an isolated, throwaway outDir. */
function build(modulesFile, outDir) {
    return execFileSync(VITE_BIN, ['build', '--outDir', outDir], {
        cwd: ROOT,
        env: { ...process.env, TRILOBIT_MODULES_FILE: modulesFile },
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });
}

test('a build with crm switched off has no src/Crm/ entry in the manifest', () => {
    const outDir = tempDir('trilobit-manifest-crm-off-');
    const modulesFile = join(outDir, 'modules.json');
    writeFileSync(modulesFile, JSON.stringify({
        modules: [
            { name: 'cms', namespace: 'Trilobit\\Cms', directory: 'src/Cms' },
            { name: 'shop', namespace: 'Trilobit\\Shop', directory: 'src/Shop' },
        ],
    }));

    build(modulesFile, outDir);

    const manifest = JSON.parse(readFileSync(join(outDir, '.vite', 'manifest.json'), 'utf8'));
    const keys = Object.keys(manifest);

    assert.ok(keys.length > 0, 'the manifest is empty');
    assert.ok(
        !keys.some((key) => key.startsWith('src/Crm/')),
        `manifest has a src/Crm/ entry despite crm being switched off: ${keys.join(', ')}`,
    );
    assert.ok(keys.includes('assets/app.ts'), 'the shared entry point is missing');
    assert.ok(keys.includes('src/Shop/assets/entry.ts'), "Shop's entry point is missing");
    assert.ok(keys.includes('src/Cms/assets/entry.ts'), "Cms's entry point is missing");

    rmSync(outDir, { recursive: true, force: true });
});

test('a missing var/build/modules.json fails the build with a readable message, not a silent build-everything', () => {
    const missing = join(tempDir('trilobit-manifest-missing-'), 'does-not-exist.json');

    assert.throws(
        () => execFileSync(VITE_BIN, ['build', '--outDir', tempDir('trilobit-manifest-missing-out-')], {
            cwd: ROOT,
            env: { ...process.env, TRILOBIT_MODULES_FILE: missing },
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'pipe'],
        }),
        (error) => {
            assert.equal(typeof error.status, 'number');
            assert.notEqual(error.status, 0);
            assert.match(String(error.stderr), /is missing\. Run "php bin\/trilobit app:warmup"/);
            assert.match(String(error.stderr), /app:warmup/);
            return true;
        },
    );
});
