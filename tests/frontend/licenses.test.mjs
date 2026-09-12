// The build copies other people's code into www/build, which is committed and
// public, and most licences - MIT and Apache-2.0 among them - are granted on the
// condition that a copy carries its copyright and licence text. A bundler strips
// both. So the build writes licenses.txt next to its output, derived from the
// modules it actually bundled rather than from package.json: a hand-kept list,
// or one read off the dependencies, drifts the first time something is bundled
// transitively or stops being used.
//
// The fixture builds below run the plugin on its own, against packages this
// test writes into a throwaway node_modules, because the real tree cannot be
// made to contain a package without a licence.
//
// Run with: npm run test:frontend

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../..', import.meta.url));
const VITE_BIN = join(ROOT, 'node_modules', '.bin', 'vite');
const PLUGIN = join(ROOT, 'vite-plugins', 'bundled-licenses.ts');

const MODULES = {
    modules: [
        { name: 'cms', namespace: 'Trilobit\\Cms', directory: 'src/Cms' },
        { name: 'crm', namespace: 'Trilobit\\Crm', directory: 'src/Crm' },
        { name: 'shop', namespace: 'Trilobit\\Shop', directory: 'src/Shop' },
    ],
};

function write(path, contents) {
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, contents);
}

/** Builds a directory of files described as { relative path: contents } with only the plugin under test. */
function buildFixture(files) {
    const root = mkdtempSync(join(tmpdir(), 'trilobit-licenses-fixture-'));
    for (const [path, contents] of Object.entries(files)) {
        write(join(root, path), contents);
    }

    write(join(root, 'vite.config.mjs'), [
        `import { bundledLicenses } from ${JSON.stringify(PLUGIN)};`,
        'export default {',
        '    logLevel: "error",',
        '    build: { outDir: "out", rollupOptions: { input: "main.js" } },',
        '    plugins: [bundledLicenses()],',
        '};',
        '',
    ].join('\n'));

    const run = () => execFileSync(VITE_BIN, ['build', '--config', 'vite.config.mjs'], {
        cwd: root,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    return { root, run };
}

test('the real build lists naja with its copyright line and licence', () => {
    const outDir = mkdtempSync(join(tmpdir(), 'trilobit-licenses-'));
    const modulesFile = join(mkdtempSync(join(tmpdir(), 'trilobit-modules-')), 'modules.json');
    writeFileSync(modulesFile, JSON.stringify(MODULES));

    execFileSync(VITE_BIN, ['build', '--outDir', outDir], {
        cwd: ROOT,
        env: { ...process.env, TRILOBIT_MODULES_FILE: modulesFile },
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    const licenses = readFileSync(join(outDir, 'licenses.txt'), 'utf8');
    const naja = JSON.parse(readFileSync(join(ROOT, 'node_modules', 'naja', 'package.json'), 'utf8'));
    const copyright = readFileSync(join(ROOT, 'node_modules', 'naja', 'LICENSE.md'), 'utf8')
        .split('\n')
        .find((line) => line.startsWith('Copyright'));

    assert.ok(copyright, "naja's licence file has no copyright line to look for");
    assert.ok(licenses.includes(`naja ${naja.version}`), 'naja is not listed');
    assert.ok(licenses.includes(`License: ${naja.license}`), "naja's licence identifier is not listed");
    assert.ok(licenses.includes(copyright), "naja's copyright line is not carried");

    // Where the checkout happens to live says nothing about the licence, and
    // would make the file differ between any two machines.
    assert.ok(!licenses.includes(ROOT), 'an absolute path leaked into the file');

    rmSync(outDir, { recursive: true, force: true });
});

// Tailwind reaches app.css through `@import "tailwindcss"`, which Tailwind's own
// compiler inlines - the package never becomes a module of a chunk, so reading
// the chunks alone left it out while the file claimed to be complete.
test('the real build lists tailwindcss, which app.css pulls in through a CSS @import', () => {
    const outDir = mkdtempSync(join(tmpdir(), 'trilobit-licenses-css-'));
    const modulesFile = join(mkdtempSync(join(tmpdir(), 'trilobit-modules-')), 'modules.json');
    writeFileSync(modulesFile, JSON.stringify(MODULES));

    execFileSync(VITE_BIN, ['build', '--outDir', outDir], {
        cwd: ROOT,
        env: { ...process.env, TRILOBIT_MODULES_FILE: modulesFile },
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    const licenses = readFileSync(join(outDir, 'licenses.txt'), 'utf8');
    const tailwind = JSON.parse(readFileSync(join(ROOT, 'node_modules', 'tailwindcss', 'package.json'), 'utf8'));
    const copyright = readFileSync(join(ROOT, 'node_modules', 'tailwindcss', 'LICENSE'), 'utf8')
        .split('\n')
        .find((line) => line.startsWith('Copyright'));

    assert.ok(copyright, "tailwindcss's licence file has no copyright line to look for");
    assert.ok(licenses.includes(`Package: tailwindcss ${tailwind.version}`), 'tailwindcss is not listed');
    assert.ok(licenses.includes(copyright), "tailwindcss's copyright line is not carried");

    // What only runs while building leaves none of its own code in the output.
    for (const tool of ['vite', '@tailwindcss/vite', 'lightningcss', 'rolldown']) {
        assert.ok(!licenses.includes(`Package: ${tool} `), `${tool} only runs at build time and is listed`);
    }

    rmSync(outDir, { recursive: true, force: true });
});

test('a package a stylesheet imports by name is listed, followed through relative imports, and a reference is not', (t) => {
    const { root, run } = buildFixture({
        'main.js': "import './style.css';\n",
        'style.css': '/* @import "commented-out"; */\n@import "./nested.css";\n.own { color: blue; }\n',
        // Only its declarations are borrowed, so nothing of it is copied out.
        'nested.css': '@reference "@example/reference-only";\n@import "@example/styled/theme.css" layer(base);\n',
        'node_modules/@example/styled/package.json': JSON.stringify({ name: '@example/styled', version: '1.0.0', license: 'MIT' }),
        'node_modules/@example/styled/LICENSE': 'Copyright (c) Styled Example Authors\n',
        'node_modules/@example/styled/theme.css': '.styled { color: red; }\n',
    });
    t.after(() => rmSync(root, { recursive: true, force: true }));

    run();

    const licenses = readFileSync(join(root, 'out', 'licenses.txt'), 'utf8');
    const listed = [...licenses.matchAll(/^Package: (.+)$/gm)].map((match) => match[1]);

    assert.deepEqual(listed, ['@example/styled 1.0.0']);
    assert.ok(licenses.includes('Copyright (c) Styled Example Authors'));
});

test('scoped, nested and sub-directory packages are each attributed to their own root, and own code to none', (t) => {
    const { root, run } = buildFixture({
        'main.js': "import { outer } from 'outer';\nimport { scoped } from '@example/scoped';\ndocument.title = outer() + scoped();\n",
        'node_modules/outer/package.json': JSON.stringify({ name: 'outer', version: '1.0.0', license: 'MIT', main: 'dist/esm/index.js' }),
        'node_modules/outer/LICENSE': 'Copyright (c) Outer Example Authors\n',
        // A package.json without a name marks a module format, not a package,
        // and must not be mistaken for the root of one.
        'node_modules/outer/dist/esm/package.json': JSON.stringify({ type: 'module' }),
        'node_modules/outer/dist/esm/index.js': "import { inner } from 'inner';\nexport const outer = () => 'o' + inner();\n",
        'node_modules/outer/node_modules/inner/package.json': JSON.stringify({ name: 'inner', version: '2.0.0', license: 'ISC', type: 'module', main: 'index.js' }),
        'node_modules/outer/node_modules/inner/licence.txt': 'Copyright (c) Inner Example Authors\n',
        'node_modules/outer/node_modules/inner/index.js': "export const inner = () => 'i';\n",
        'node_modules/@example/scoped/package.json': JSON.stringify({ name: '@example/scoped', version: '3.0.0', license: 'Apache-2.0', type: 'module', main: 'index.js' }),
        'node_modules/@example/scoped/COPYING': 'Copyright (c) Scoped Example Authors\n',
        'node_modules/@example/scoped/index.js': "export const scoped = () => 's';\n",
    });
    t.after(() => rmSync(root, { recursive: true, force: true }));

    run();

    const licenses = readFileSync(join(root, 'out', 'licenses.txt'), 'utf8');
    const listed = [...licenses.matchAll(/^Package: (.+)$/gm)].map((match) => match[1]);

    assert.deepEqual(listed, ['@example/scoped 3.0.0', 'inner 2.0.0', 'outer 1.0.0']);
    assert.ok(licenses.includes('Copyright (c) Inner Example Authors'));
    assert.ok(licenses.includes('License: Apache-2.0'));
    assert.ok(!licenses.includes(root), 'an absolute path leaked into the file');
});

test('a bundled package without a licence file fails the build and is named', (t) => {
    const { root, run } = buildFixture({
        'main.js': "import { quiet } from '@example/unlicensed';\ndocument.title = quiet();\n",
        'node_modules/@example/unlicensed/package.json': JSON.stringify({ name: '@example/unlicensed', version: '0.1.0', license: 'MIT', type: 'module', main: 'index.js' }),
        'node_modules/@example/unlicensed/index.js': "export const quiet = () => 'q';\n",
    });
    t.after(() => rmSync(root, { recursive: true, force: true }));

    assert.throws(run, (error) => {
        assert.notEqual(error.status, 0);
        assert.match(String(error.stderr), /@example\/unlicensed 0\.1\.0 has no licence file/);
        return true;
    });
});

test('a bundled package without a license field fails the build and is named', (t) => {
    const { root, run } = buildFixture({
        'main.js': "import { quiet } from 'unlabelled';\ndocument.title = quiet();\n",
        'node_modules/unlabelled/package.json': JSON.stringify({ name: 'unlabelled', version: '0.2.0', type: 'module', main: 'index.js' }),
        'node_modules/unlabelled/LICENSE': 'Copyright (c) Unlabelled Example Authors\n',
        'node_modules/unlabelled/index.js': "export const quiet = () => 'q';\n",
    });
    t.after(() => rmSync(root, { recursive: true, force: true }));

    assert.throws(run, (error) => {
        assert.notEqual(error.status, 0);
        assert.match(String(error.stderr), /unlabelled 0\.2\.0 declares no "license"/);
        return true;
    });
});
