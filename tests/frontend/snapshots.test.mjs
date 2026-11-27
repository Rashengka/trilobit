// The decisions of the snapshot matrix that need no browser (bin/snapshots.mjs,
// tests/snapshots/matrix.mjs): how many pictures a path is, what each is
// called, which answers are refused instead of pictured, and that the index
// says so. The run itself - Docker, a server, the browsers - is claimed by
// tests/snapshots/tool.test.mjs (`npm run snapshots:test`).
//
// Run with: npm run test:frontend

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    browsersFor,
    checkPaths,
    clickRefusal,
    combinations,
    drawnRefusal,
    fileName,
    indexPage,
    refusal,
    resizedNote,
    slugOf,
    SCROLLBAR_WIDTH,
    widthRefusal,
} from '../snapshots/matrix.mjs';

test('a path is two themes, two modes and two widths in each browser', () => {
    assert.equal(combinations().length, 8);
    assert.equal(new Set(combinations().map((c) => `${c.theme}-${c.mode}-${c.width}`)).size, 8);
    assert.deepEqual(browsersFor('all'), ['chromium', 'firefox']);
    assert.deepEqual(browsersFor('firefox'), ['firefox']);
    assert.throws(() => browsersFor('safari'), /safari/);
});

test('a picture is named after its path and its combination', () => {
    assert.equal(
        fileName('/admin/shop/products', { theme: 'ledger', mode: 'dark', width: 390 }, 'firefox'),
        'admin-shop-products__ledger-dark-390-firefox.png',
    );
    assert.equal(slugOf('/'), 'home');
    assert.equal(slugOf('/_styleguide/foundations/colours'), 'styleguide-foundations-colours');
    assert.equal(slugOf('/admin/shop/products?products-name=Ab'), 'admin-shop-products-products-name-ab');
});

test('paths that are not this site\'s, or would share a file, are refused before anything runs', () => {
    assert.throws(() => checkPaths([]), /no path/);
    assert.throws(() => checkPaths(['admin']), /single "\/"/);
    assert.throws(() => checkPaths(['//example.com/x']), /single "\/"/);
    assert.throws(() => checkPaths(['/a-b', '/a/b']), /both be saved as a-b/);
    assert.deepEqual(checkPaths(['/a', '/b']), ['/a', '/b']);
});

test('a redirect to the sign-in page is a failure, not a picture of the sign-in page', () => {
    const answer = { status: 200, redirected: true, arrivedAt: '/admin/sign-in', origin: 'http://h' };
    assert.match(refusal('/admin/shop/products', answer, 'http://h'), /redirected to the sign-in page/);
    assert.match(refusal('/admin/shop/products', answer, 'http://h'), /--sign-in/);
});

test('any other redirect, any status but 200 and no answer are failures too', () => {
    assert.match(refusal('/old', { status: 200, redirected: true, arrivedAt: '/new', origin: 'http://h' }, 'http://h'), /redirected to \/new/);
    assert.match(refusal('/gone', { status: 404, redirected: false, arrivedAt: '/gone', origin: 'http://h' }, 'http://h'), /answered 404/);
    assert.match(refusal('/x', { status: null, redirected: false, arrivedAt: '/x', origin: 'http://h' }, 'http://h'), /no response/);
    assert.equal(refusal('/x', { status: 200, redirected: false, arrivedAt: '/x', origin: 'http://h' }, 'http://h'), null);
});

test('the index shows a failure and a picture never reported as red cells, and escapes what it shows', () => {
    const combination = { theme: 'atrium', mode: 'light', width: 390 };
    const taken = fileName('/a', combination, 'chromium');
    const refused = fileName('/a', { ...combination, width: 1440 }, 'chromium');
    const html = indexPage({
        title: 'Snapshots test',
        paths: ['/a'],
        browsers: ['chromium'],
        results: [
            { file: taken, error: null },
            { file: refused, error: '/a answered <500>.' },
        ],
    });

    assert.match(html, new RegExp(`<img src="${taken}"`));
    assert.match(html, /<td class="failed">\/a answered &lt;500&gt;\.<\/td>/);
    // Eight cells for the one path and browser: one picture, one failure, six never reported.
    assert.equal(html.match(/<td class="missing">/g)?.length, 6);
});

test('a backslash right after the first slash is refused, since a browser reads it as another host', () => {
    assert.throws(() => checkPaths(['/\\example.com']), /single "\/"/);
    assert.throws(() => checkPaths(['/a\\b']), /backslash/);
});

test('a page that ends up on another origin is a failure', () => {
    const answer = { status: 200, redirected: false, arrivedAt: '/x', origin: 'http://example.com' };
    assert.match(refusal('/x', answer, 'http://127.0.0.1:18100'), /ended up on http:\/\/example\.com/);
    assert.equal(refusal('/x', { ...answer, origin: 'http://127.0.0.1:18100' }, 'http://127.0.0.1:18100'), null);
});

test('a page drawn in another theme or mode than the one asked for is a failure, not a picture filed under the wrong name', () => {
    const wanted = { theme: 'ledger', mode: 'dark' };
    assert.equal(drawnRefusal('/x', wanted, { theme: 'ledger', mode: 'dark' }), null);
    assert.match(drawnRefusal('/x', wanted, { theme: 'atrium', mode: 'dark' }), /drawn as atrium dark, not ledger dark/);
    assert.match(drawnRefusal('/x', wanted, { theme: 'ledger', mode: 'light' }), /drawn as ledger light/);
    assert.match(drawnRefusal('/x', wanted, { theme: null, mode: null }), /drawn as no theme no mode/);
});

test('a click that leaves the page is a failure, since the picture would be of another page', () => {
    assert.equal(clickRefusal('/x', '#menu', 'http://h/x', 'http://h/x'), null);
    assert.equal(clickRefusal('/x', '#menu', 'http://h/x', 'http://h/x#open'), null);
    assert.match(clickRefusal('/x', '#menu', 'http://h/x', 'http://h/y'), /clicking #menu on \/x went on to http:\/\/h\/y/);
    assert.match(clickRefusal('/x', '#menu', 'http://h/x', 'http://h/x?page=2'), /went on to/);
});

test('a page laid out in a window of another width than the one asked for is a failure, not a picture filed under that width', () => {
    // A window of the width asked for, with or without a classic scrollbar taking its room.
    assert.equal(widthRefusal('/x', 'firefox', 390, { window: 390, layout: 390 }), null);
    assert.equal(widthRefusal('/x', 'firefox', 390, { window: 390, layout: 375 }), null);
    assert.equal(widthRefusal('/x', 'chromium', 1440, { window: 1440, layout: 1425 }), null);
    assert.equal(widthRefusal('/x', 'chromium', 1440, { window: 1440, layout: 1440 - SCROLLBAR_WIDTH }), null);
    assert.equal(SCROLLBAR_WIDTH, 15);

    // What a run took as a picture at 390 and at 1440: Firefox's own window, not the one asked for.
    assert.match(
        widthRefusal('/_styleguide/forms/controls', 'firefox', 390, { window: 1152, layout: 1137 }),
        /\/_styleguide\/forms\/controls was laid out in firefox in a window 1152px wide \(1137px beside the scrollbar\), not 390px/,
    );
    assert.match(widthRefusal('/x', 'firefox', 1440, { window: 1152, layout: 1137 }), /window 1152px wide .*not 1440px/);

    // The window right, the page laid out narrower than any scrollbar explains, or wider than the window.
    assert.match(widthRefusal('/x', 'chromium', 1440, { window: 1440, layout: 1137 }), /1137px wide beside the scrollbar of a window 1440px wide/);
    assert.match(widthRefusal('/x', 'chromium', 390, { window: 390, layout: 391 }), /391px wide beside the scrollbar of a window 390px wide/);

    // Any room but exactly one scrollbar's, a pixel either way included.
    assert.match(widthRefusal('/x', 'chromium', 390, { window: 390, layout: 389 }), /389px wide .*the scrollbar is 15px or none/);
    assert.match(widthRefusal('/x', 'chromium', 390, { window: 390, layout: 374 }), /374px wide/);
    assert.match(widthRefusal('/x', 'firefox', 390, { window: 390, layout: 376 }), /376px wide/);
    assert.match(widthRefusal('/x', 'firefox', 390, { window: 390, layout: 370 }), /370px wide/);
});

test('a picture whose window was given its size again says what was first measured, the window or the layout', () => {
    assert.equal(resizedNote({ width: 390, resized: false, firstLayout: null }), null);
    assert.equal(
        resizedNote({ width: 390, resized: true, firstLayout: { window: 1152, width: 1137 } }),
        'asked for 390px, the window was at first 1152px wide with the page laid out 1137px wide beside it, and was given its size again',
    );
    // The window right and the layout off: the note does not claim the window was another width.
    assert.match(resizedNote({ width: 1440, resized: true, firstLayout: { window: 1440, width: 1137 } }), /window was at first 1440px wide with the page laid out 1137px wide/);
});

test('the index marks a picture whose window was given its size again, and leaves the others unmarked', () => {
    const combination = { theme: 'atrium', mode: 'light', width: 390 };
    const resized = fileName('/a', combination, 'firefox');
    const plain = fileName('/a', { ...combination, width: 1440 }, 'firefox');
    const html = indexPage({
        title: 'Snapshots test',
        paths: ['/a'],
        browsers: ['firefox'],
        results: [
            { file: resized, width: 390, error: null, resized: true, firstLayout: { window: 1152, width: 1137 } },
            { file: plain, width: 1440, error: null, resized: false, firstLayout: null },
        ],
    });

    const note = 'asked for 390px, the window was at first 1152px wide with the page laid out 1137px wide beside it, and was given its size again';
    assert.match(html, new RegExp(`<td class="resized" title="${note}"><a href="${resized}">.*?</a><p>RESIZED: ${note}</p></td>`));
    assert.match(html, new RegExp(`<td><a href="${plain}">`));
    assert.equal(html.match(/class="resized"/g)?.length, 1);
});
