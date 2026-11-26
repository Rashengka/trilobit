// The snapshot matrix run for real, the way somebody runs it: bin/snapshots.mjs,
// in the browser suite's image, against this checkout's database. Two claims a
// unit test of tests/snapshots/matrix.mjs cannot make, because they are about
// what actually reaches the disk:
//
//   - a page of the administration asked for without --sign-in fails the run
//     and leaves no picture behind - the sign-in page is never filed under the
//     name of the page asked for;
//   - a page that answers leaves exactly the pictures of its matrix, each one a
//     PNG, and an index that shows them - in both browsers, each drawing the
//     classic scrollbar of Linux on a page that scrolls, as CI's does;
//   - --sign-in pictures the administration, says nothing on the way that has
//     the shape of the password app:account prints, and leaves no session
//     behind in the checkout.
//
// It needs Docker and this checkout's database (`docker compose up -d
// database`), as `npm run e2e:docker` does, and takes a minute; it is not part
// of `npm run test:frontend` for that reason.
//
// Run with: npm run snapshots:test

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { combinations, fileName } from './matrix.mjs';

const ROOT = fileURLToPath(new URL('../..', import.meta.url));

/** One run of the tool into a directory of its own, removed before and after. */
function run(name, ...args) {
    const out = join(ROOT, 'var', 'snapshots', name);
    rmSync(out, { recursive: true, force: true });
    const result = spawnSync(process.execPath, [join(ROOT, 'bin', 'snapshots.mjs'), `--run=${name}`, ...args], {
        cwd: ROOT,
        encoding: 'utf8',
    });

    return { out, status: result.status, output: `${result.stdout}${result.stderr}` };
}

/** Trilobit\Core\Console\AccountCommand::PASSWORD_LINE - the shape, not a value. */
const passwordLine = /^ {2}(\S+)$/m;

/** What the spec reported of each picture, by file name. */
function results(directory) {
    const folder = join(directory, 'results');

    return readdirSync(folder).map((entry) => JSON.parse(readFileSync(join(folder, entry), 'utf8')));
}

const pngs = (directory) => readdirSync(directory).filter((entry) => entry.endsWith('.png'));

test('a page of the administration without --sign-in fails the run and is not pictured', (t) => {
    const { out, status, output } = run('tool-test-signed-out', '--browser=chromium', '/admin/shop/products');
    t.after(() => rmSync(out, { recursive: true, force: true }));

    assert.notEqual(status, 0, output);
    assert.match(output, /0 of 8 pictures taken/);
    assert.match(output, /redirected to the sign-in page/);
    assert.deepEqual(pngs(out), []);
    assert.match(readFileSync(join(out, 'index.html'), 'utf8'), /<td class="failed">[^<]*sign-in page/);
});

test('a page that answers leaves every picture of its matrix and an index of them', (t) => {
    const path = '/_styleguide/foundations/colours';
    const { out, status, output } = run('tool-test-styleguide', '--browser=all', path);
    t.after(() => rmSync(out, { recursive: true, force: true }));

    assert.equal(status, 0, output);
    const expected = ['chromium', 'firefox']
        .flatMap((browser) => combinations().map((combination) => fileName(path, combination, browser)))
        .sort();
    assert.deepEqual(pngs(out).sort(), expected);
    for (const file of expected) {
        const signature = readFileSync(join(out, file)).subarray(0, 8);
        assert.deepEqual([...signature], [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a], `${file} is not a PNG`);
    }
    const index = readFileSync(join(out, 'index.html'), 'utf8');
    assert.equal(index.match(/<img /g)?.length, expected.length);
    assert.doesNotMatch(index, /class="(failed|missing)"/);

    // The page is longer than any window it is opened in, so a classic
    // scrollbar takes room in every picture. Headless, neither browser draws
    // one unless told to.
    for (const result of results(out)) {
        assert.ok(result.scrollbar > 0, `${result.file} was taken with no scrollbar taking room (${result.scrollbar})`);
    }
});

test('--sign-in pictures the administration without saying the password or leaving the session behind', (t) => {
    const { out, status, output } = run('tool-test-signed-in', '--sign-in', '--browser=chromium', '/admin/shop/products');
    t.after(() => rmSync(out, { recursive: true, force: true }));

    assert.equal(status, 0, output);
    assert.match(output, /8 of 8 pictures taken/);
    assert.equal(pngs(out).length, 8);
    assert.doesNotMatch(output, passwordLine, 'the run printed a line with the shape of a generated password');
    assert.equal(existsSync(join(ROOT, 'var', 'tmp', 'snapshot-session.json')), false);
});
