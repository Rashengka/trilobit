// The snapshot matrix run for real, the way somebody runs it: bin/snapshots.mjs,
// in the browser suite's image, against this checkout's database. Claims a
// unit test of tests/snapshots/matrix.mjs cannot make, because they are about
// what actually reaches the disk:
//
//   - a page of the administration asked for without --sign-in fails the run
//     and leaves no picture behind - the sign-in page is never filed under the
//     name of the page asked for;
//   - a page that answers leaves exactly the pictures of its matrix, each one a
//     PNG, and an index that shows them - in both browsers, each drawing the
//     classic scrollbar of Linux on a page that scrolls, as CI's does;
//   - a picture is the page exactly as laid out beside the classic scrollbar,
//     in both browsers: as wide and as tall as the layout, with its right edge
//     and its bottom in it (tests/snapshots/fixtures/edges.html);
//   - --sign-in pictures the administration, says nothing on the way that has
//     the shape of the password app:account prints, and leaves no session
//     behind - neither in the checkout nor in a container that outlived it.
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
import { inflateSync } from 'node:zlib';

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

/**
 * A PNG as its size and a way to read one pixel, enough of the format for what
 * the browsers write: eight bits a channel, RGB or RGBA, not interlaced.
 */
function decodePng(file) {
    const data = readFileSync(file);
    const width = data.readUInt32BE(16);
    const height = data.readUInt32BE(20);
    const [depth, colour, , , interlace] = data.subarray(24, 29);
    assert.equal(depth, 8, `${file} has ${depth} bits a channel`);
    assert.ok(colour === 2 || colour === 6, `${file} is of colour type ${colour}`);
    assert.equal(interlace, 0, `${file} is interlaced`);
    const channels = colour === 6 ? 4 : 3;

    const chunks = [];
    for (let at = 8; at < data.length;) {
        const length = data.readUInt32BE(at);
        if (data.toString('latin1', at + 4, at + 8) === 'IDAT') {
            chunks.push(data.subarray(at + 8, at + 8 + length));
        }
        at += 12 + length;
    }
    const raw = inflateSync(Buffer.concat(chunks));

    const stride = width * channels;
    const pixels = Buffer.alloc(stride * height);
    for (let y = 0; y < height; y++) {
        const filter = raw[y * (stride + 1)];
        for (let x = 0; x < stride; x++) {
            const value = raw[y * (stride + 1) + 1 + x];
            const left = x >= channels ? pixels[y * stride + x - channels] : 0;
            const up = y > 0 ? pixels[(y - 1) * stride + x] : 0;
            const upLeft = x >= channels && y > 0 ? pixels[(y - 1) * stride + x - channels] : 0;
            let predicted = 0;
            if (filter === 1) {
                predicted = left;
            } else if (filter === 2) {
                predicted = up;
            } else if (filter === 3) {
                predicted = (left + up) >> 1;
            } else if (filter === 4) {
                const estimate = left + up - upLeft;
                const [a, b, c] = [Math.abs(estimate - left), Math.abs(estimate - up), Math.abs(estimate - upLeft)];
                predicted = a <= b && a <= c ? left : (b <= c ? up : upLeft);
            }
            pixels[y * stride + x] = (value + predicted) & 0xff;
        }
    }

    const pixel = (x, y) => {
        const at = y * stride + x * channels;

        return `#${[...pixels.subarray(at, at + 3)].map((value) => value.toString(16).padStart(2, '0')).join('')}`;
    };

    return { width, height, pixel };
}

/** The width of a picture, read off its header without decoding it. */
const pngWidth = (file) => readFileSync(file).readUInt32BE(16);

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
    // scrollbar takes room in every picture: each is narrower than its window.
    // Headless, neither browser draws one unless told to. Read off the PNG,
    // because the picture is the claim - a window can have had a scrollbar
    // while the picture was taken without one.
    for (const result of results(out)) {
        const scrollbar = result.width - pngWidth(join(out, result.file));
        assert.ok(scrollbar > 0, `${result.file} was taken with no scrollbar taking room (${scrollbar})`);
    }
});

test('a picture is the page as laid out beside the scrollbar, every edge of it and nothing past it', (t) => {
    // tests/snapshots/fixtures/edges.html, served by the spec: green against
    // the left edge of the layout, red against the right, blue along the bottom.
    const path = '/_snapshot-fixtures/edges.html';
    const { out, status, output } = run('tool-test-edges', '--browser=all', path);
    t.after(() => rmSync(out, { recursive: true, force: true }));

    assert.equal(status, 0, output);
    const all = results(out);
    assert.equal(all.length, 16);
    for (const result of all) {
        const picture = decodePng(join(out, result.file));
        const middle = Math.floor(picture.height / 2);
        const where = `${result.file} (${picture.width}x${picture.height}, laid out at ${result.layout.width}x${result.layout.height})`;

        assert.ok(result.layout.width < result.width, `${where}: no scrollbar took room beside the page`);
        assert.equal(picture.width, result.layout.width, `${where}: not as wide as the page was laid out`);
        assert.equal(picture.height, result.layout.height, `${where}: not as tall as the page was laid out`);
        assert.equal(picture.pixel(0, middle), '#00ff00', `${where}: the left edge of the layout is not its first column`);
        assert.equal(picture.pixel(picture.width - 1, middle), '#ff0000', `${where}: the right edge of the layout is not its last column`);
        assert.equal(picture.pixel(Math.floor(picture.width / 2), picture.height - 1), '#0000ff', `${where}: the bottom of the layout is not its last row`);
    }
});

test('--sign-in pictures the administration without saying the password or leaving the session behind', (t) => {
    const { out, status, output } = run('tool-test-signed-in', '--sign-in', '--browser=chromium', '/admin/shop/products');
    t.after(() => rmSync(out, { recursive: true, force: true }));

    assert.equal(status, 0, output);
    assert.match(output, /8 of 8 pictures taken/);
    assert.equal(pngs(out).length, 8);
    assert.doesNotMatch(output, passwordLine, 'the run printed a line with the shape of a generated password');
    // The session is written inside the container, to var/tmp, which
    // bin/e2e-docker.mjs mounts there as a tmpfs: a file on the host's var/tmp
    // would only be seen if that mount were gone. What keeps the session from
    // outliving the run is the container going with its tmpfs, so that is
    // asked of Docker - and the host is looked at too, for the mount lost.
    assert.equal(existsSync(join(ROOT, 'var', 'tmp', 'snapshot-session.json')), false);
    const database = spawnSync('docker', ['compose', 'ps', '--quiet', 'database'], { cwd: ROOT, encoding: 'utf8' }).stdout.trim();
    const project = spawnSync('docker', ['inspect', '--format', '{{index .Config.Labels "com.docker.compose.project"}}', database], {
        encoding: 'utf8',
    }).stdout.trim();
    assert.notEqual(project, '', 'cannot tell which compose project the run used');
    const left = spawnSync('docker', ['ps', '--all', '--quiet', '--filter', `name=^/${project}-e2e$`], { encoding: 'utf8' });
    assert.equal(left.status, 0, left.stderr);
    assert.equal(left.stdout.trim(), '', `the container ${project}-e2e, and the session in its tmpfs, outlived the run`);
});
