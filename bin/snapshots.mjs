#!/usr/bin/env node
//
// snapshots - pictures of named pages in every theme, mode, width and browser,
// taken where CI runs the browser suite, for somebody to look at side by side.
//
//   node bin/snapshots.mjs [--sign-in] [--click=<selector>] [--browser=chromium|firefox|all]
//                          [--run=<name>] <path>... [playwright test arguments]
//   npm run snapshots -- --sign-in /admin/shop/products /_styleguide/foundations/colours
//
// A thin layer over bin/e2e-docker.mjs: the same image, the same Linux fonts,
// the same server, run with playwright.snapshots.config.ts instead of the
// suite's configuration. Both browsers are made to lay the page out beside the
// classic scrollbar of Linux, fifteen pixels wide, which headless they would
// not (tests/snapshots/matrix.spec.ts says how). A whole-page picture of a page
// that scrolls is therefore fifteen pixels narrower than the window, as the
// page is on a build server. A picture taken on a Mac has overlay scrollbars
// that take no room and macOS fonts, which is not what CI - or most visitors -
// see.
//
// The matrix is the themes atrium and ledger, the modes light and dark, the
// widths 390 and 1440, in Chromium and Firefox unless --browser narrows it:
// sixteen pictures per path. Each one is a whole page, saved as
//
//   var/snapshots/<run>/<path>__<theme>-<mode>-<width>-<browser>.png
//
// beside an index.html that lays them out as a grid. <run> is the time the run
// started unless --run names it.
//
// --sign-in makes an account of its own in this checkout's database and signs
// in once before any picture (tests/snapshots/global-setup.ts). Without it the
// administration redirects to the sign-in page, and that is reported as a
// failure, never pictured. So is any other redirect, and any status but 200.
//
// --click clicks the first element the selector matches before each picture,
// to show a menu or a dialog open.
//
// Exit codes: 0 when every picture of the matrix was taken, 1 when any was
// not (each is named, and is a red cell of the index), 2 when the run could
// not be set up at all.
//

import { spawn } from 'node:child_process';
import { mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
    browsersFor,
    checkPaths,
    combinations,
    fileName,
    indexPage,
    REQUEST_VARIABLE,
} from '../tests/snapshots/matrix.mjs';

const ROOT = fileURLToPath(new URL('..', import.meta.url)).replace(/\/$/, '');

function fail(message) {
    process.stderr.write(`snapshots: ${message}\n`);
    process.exit(2);
}

function timestamp() {
    return new Date().toISOString().replace(/\.\d+Z$/, '').replaceAll(':', '').replace('T', '-');
}

let signIn = false;
let click = null;
let browser = 'all';
let run = timestamp();
const paths = [];
const passed = [];
for (const argument of process.argv.slice(2)) {
    let match;
    if (argument === '--sign-in') {
        signIn = true;
    } else if ((match = /^--click=(.+)$/.exec(argument)) !== null) {
        click = match[1];
    } else if ((match = /^--browser=(.*)$/.exec(argument)) !== null) {
        browser = match[1];
    } else if ((match = /^--run=(.+)$/.exec(argument)) !== null) {
        run = match[1];
    } else if (argument.startsWith('/')) {
        paths.push(argument);
    } else {
        passed.push(argument);
    }
}

let browsers;
try {
    checkPaths(paths);
    browsers = browsersFor(browser);
} catch (error) {
    fail(error.message);
}
if (!/^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(run)) {
    fail(`--run names a directory under var/snapshots; "${run}" is not a plain name.`);
}

const out = join(ROOT, 'var', 'snapshots', run);
mkdirSync(out, { recursive: true });
for (const entry of readdirSync(out)) {
    if (entry.endsWith('.png') || entry === 'results' || entry === 'index.html') {
        fail(`${relative(ROOT, out)} already holds a run; name another with --run.`);
    }
}

const env = {
    ...process.env,
    [REQUEST_VARIABLE]: JSON.stringify({ paths, signIn, click, out }),
};

const child = spawn(
    process.execPath,
    [join(ROOT, 'bin', 'e2e-docker.mjs'), `--browser=${browser}`, '--config=playwright.snapshots.config.ts', ...passed],
    { cwd: ROOT, env, stdio: 'inherit' },
);

child.on('exit', (code, signal) => {
    if (code === 2) {
        // e2e-docker could not set the run up, and has said why.
        process.exit(2);
    }

    const resultsDirectory = join(out, 'results');
    let results = [];
    try {
        results = readdirSync(resultsDirectory)
            .filter((entry) => entry.endsWith('.json'))
            .map((entry) => JSON.parse(readFileSync(join(resultsDirectory, entry), 'utf8')));
    } catch (error) {
        if (error.code !== 'ENOENT') {
            throw error;
        }
    }

    const expected = [];
    for (const path of paths) {
        for (const name of browsers) {
            for (const combination of combinations()) {
                expected.push(fileName(path, combination, name));
            }
        }
    }

    const byFile = new Map(results.map((result) => [result.file, result]));
    const failed = results.filter((result) => result.error !== null);
    const missing = expected.filter((file) => !byFile.has(file));
    const taken = expected.length - failed.length - missing.length;

    writeFileSync(join(out, 'index.html'), indexPage({ title: `Snapshots ${run}`, paths, browsers, results }));

    const lines = [`snapshots: ${taken} of ${expected.length} pictures taken, in ${relative(ROOT, out)}/index.html`];
    for (const result of failed) {
        lines.push(`  FAILED  ${result.file}: ${result.error}`);
    }
    if (missing.length > 0) {
        lines.push(`  ${missing.length} never reported - see the run's output above for why - first ${missing[0]}`);
    }
    process.stdout.write(`${lines.join('\n')}\n`);

    if (taken !== expected.length || code !== 0) {
        process.exit(code !== null && code !== 0 ? code : 1);
    }
    process.exit(signal === null ? 0 : 1);
});
