// playwright.config.ts starts PHP's server for the browser suite, and on a
// developer's machine it takes over a server that already answers on the port
// instead of failing. That port used to be the same for every checkout, so a
// server another worktree left running was taken over in silence: the specs
// ran against that checkout's code and database, while what they prepared
// through bin/trilobit went into this checkout's. The run was green, and a
// green run over somebody else's code looks exactly like a green run over
// one's own.
//
// What is claimed here is the way out: every checkout has a port of its own,
// and a server is taken over only once it has proven to belong to this
// checkout. The claims about the server are made by running the real
// `playwright test` against the real configuration, with a server started here
// standing on the port it is told to use. No spec is selected
// (--pass-with-no-tests), so no browser is launched and no database is needed:
// the decision whether to run at all is taken before the first test would
// start, and that decision is what is under test.
//
// CI is removed from the environment of that run, because on a build server the
// configuration never takes a server over, and the behaviour tested here is the
// one a developer's machine gets.
//
// Run with: npm run test:frontend

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { copyFileSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../..', import.meta.url));
const PLAYWRIGHT = join(ROOT, 'node_modules', '.bin', 'playwright');
const IDENTITY_SCRIPT = join(ROOT, 'tests', 'e2e', 'checkout-identity.php');

/** Imported where it is used, so that the claims about the server run - and fail - without it. */
async function checkout() {
    return import('../e2e/checkout.mjs');
}

function tempDir(prefix) {
    return mkdtempSync(join(tmpdir(), prefix));
}

/** A port nothing listens on at the moment of asking. */
async function freePort() {
    const server = createServer();
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const { port } = server.address();
    await new Promise((resolve) => server.close(resolve));

    return port;
}

/**
 * A directory standing in for a checkout: www/index.php answers every path it
 * is asked for with a page of its own, the way the application's front
 * controller does.
 */
function fakeCheckout(page) {
    const directory = tempDir('trilobit-e2e-checkout-');
    mkdirSync(join(directory, 'www'));
    writeFileSync(join(directory, 'www', 'index.php'), `<?php echo ${JSON.stringify(page)};\n`);

    return directory;
}

/**
 * PHP's server on a free port, serving $docroot, with $prepend loaded before
 * every script. Resolves once it answers, with the paths it has been asked for
 * so far available through requests().
 */
async function startServer({ docroot, prepend, env }) {
    const port = await freePort();
    const args = [...(prepend === undefined ? [] : ['-d', `auto_prepend_file=${prepend}`]), '-S', `127.0.0.1:${port}`, '-t', docroot];
    const child = spawn('php', args, { env, stdio: ['ignore', 'ignore', 'pipe'] });
    let log = '';
    child.stderr.setEncoding('utf8');
    child.stderr.on('data', (chunk) => {
        log += chunk;
    });

    const deadline = Date.now() + 10_000;
    for (;;) {
        try {
            const response = await fetch(`http://127.0.0.1:${port}/`);
            await response.text();
            break;
        } catch (error) {
            if (Date.now() > deadline || child.exitCode !== null) {
                child.kill();
                throw new Error(`php -S on ${port} did not start: ${error.message}\n${log}`);
            }
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
    }

    return {
        port,
        requests: () => [...log.matchAll(/\]: GET (\S+)/g)].map((match) => match[1]),
        async stop() {
            if (child.exitCode === null) {
                const exited = new Promise((resolve) => child.once('exit', resolve));
                child.kill();
                await exited;
            }
        },
    };
}

/** The process environment without CI, and without whatever mode the shell happens to be in. */
function localEnv(extra = {}) {
    const env = { ...process.env, ...extra };
    delete env.CI;
    if (!('TRILOBIT_ENV' in extra)) {
        delete env.TRILOBIT_ENV;
    }

    return env;
}

/**
 * The real `playwright test`, told to use $port, selecting no spec.
 *
 * Asynchronous on purpose: the servers started above report the requests they
 * get through a pipe this process reads, and a synchronous run would keep it
 * from reading until the run was over.
 */
async function runSuite(port) {
    const output = tempDir('trilobit-e2e-output-');
    try {
        const child = spawn(PLAYWRIGHT, [
            'test',
            '--grep', 'a title no spec has',
            '--pass-with-no-tests',
            '--reporter=line',
            // Not test-results/: Playwright empties its output directory first,
            // and that one may hold the traces of a developer's last real run.
            `--output=${output}`,
        ], {
            cwd: ROOT,
            env: localEnv({ TRILOBIT_E2E_PORT: String(port) }),
            stdio: ['ignore', 'pipe', 'pipe'],
            timeout: 120_000,
        });
        let text = '';
        child.stdout.setEncoding('utf8').on('data', (chunk) => {
            text += chunk;
        });
        child.stderr.setEncoding('utf8').on('data', (chunk) => {
            text += chunk;
        });
        const status = await new Promise((resolve) => child.once('close', resolve));

        return { status, output: text };
    } finally {
        rmSync(output, { recursive: true, force: true });
    }
}

test('a server of a checkout without the identity check is refused, and nothing is run against it', async () => {
    const other = fakeCheckout('a page of another checkout');
    const server = await startServer({ docroot: join(other, 'www'), env: localEnv({ TRILOBIT_ENV: 'dev' }) });
    try {
        const run = await runSuite(server.port);

        assert.notEqual(run.status, 0, `the suite accepted another checkout's server on port ${server.port}:\n${run.output}`);
        assert.match(run.output, new RegExp(`port ${server.port}.*another checkout`, 's'));
        assert.match(run.output, /TRILOBIT_E2E_PORT/);
    } finally {
        await server.stop();
        rmSync(other, { recursive: true, force: true });
    }
});

test('a server of another checkout that has the identity check is refused as well', async () => {
    // The same identity script, loaded from a different checkout: it answers,
    // but with that checkout's identity rather than this one's.
    const other = fakeCheckout('a page of another checkout');
    mkdirSync(join(other, 'tests', 'e2e'), { recursive: true });
    copyFileSync(IDENTITY_SCRIPT, join(other, 'tests', 'e2e', 'checkout-identity.php'));
    const server = await startServer({
        docroot: join(other, 'www'),
        prepend: join(other, 'tests', 'e2e', 'checkout-identity.php'),
        env: localEnv({ TRILOBIT_ENV: 'dev' }),
    });
    try {
        const run = await runSuite(server.port);

        assert.notEqual(run.status, 0, `the suite accepted another checkout's server on port ${server.port}:\n${run.output}`);
        assert.match(run.output, new RegExp(`port ${server.port}.*another checkout`, 's'));
    } finally {
        await server.stop();
        rmSync(other, { recursive: true, force: true });
    }
});

test("this checkout's own running server is taken over, after it has been asked who it is", async () => {
    const { IDENTITY_PATH } = await checkout();
    const docroot = fakeCheckout('a page of this checkout');
    const server = await startServer({
        docroot: join(docroot, 'www'),
        prepend: IDENTITY_SCRIPT,
        env: localEnv({ TRILOBIT_ENV: 'dev' }),
    });
    try {
        const run = await runSuite(server.port);

        assert.equal(run.status, 0, `the suite refused this checkout's own server:\n${run.output}`);
        // Proof that the check reached the server rather than passing by
        // default: the server was asked the question.
        assert.ok(server.requests().includes(IDENTITY_PATH), `the server was never asked who it is: ${server.requests().join(', ')}`);
    } finally {
        await server.stop();
        rmSync(docroot, { recursive: true, force: true });
    }
});

test("outside dev, this checkout's server does not answer who it is, so it is not taken over", async () => {
    const { IDENTITY_PATH } = await checkout();
    const docroot = fakeCheckout('a page of this checkout');
    const server = await startServer({ docroot: join(docroot, 'www'), prepend: IDENTITY_SCRIPT, env: localEnv() });
    try {
        const response = await fetch(`http://127.0.0.1:${server.port}${IDENTITY_PATH}`);
        assert.equal(await response.text(), 'a page of this checkout', 'the identity is answered outside dev');

        const run = await runSuite(server.port);

        assert.notEqual(run.status, 0, `the suite accepted a server that is not in dev:\n${run.output}`);
    } finally {
        await server.stop();
        rmSync(docroot, { recursive: true, force: true });
    }
});

test('the port is taken from the environment first, then from .env, then the default', async () => {
    const { e2ePort, DEFAULT_PORT } = await checkout();
    const directory = tempDir('trilobit-e2e-port-');
    try {
        assert.equal(e2ePort(directory, {}), DEFAULT_PORT, 'no .env and no variable');

        writeFileSync(join(directory, '.env'), '# a comment\nTRILOBIT_DB_PORT=13306\nTRILOBIT_E2E_PORT=18103\n');
        assert.equal(e2ePort(directory, {}), 18103, 'from .env');
        assert.equal(e2ePort(directory, { TRILOBIT_E2E_PORT: '18150' }), 18150, 'the environment wins over .env');
        assert.equal(e2ePort(directory, { TRILOBIT_E2E_PORT: '' }), 18103, 'an empty variable is no variable');

        writeFileSync(join(directory, '.env'), 'TRILOBIT_E2E_PORT = "18104"\n');
        assert.equal(e2ePort(directory, {}), 18104, 'spaces and quotes as the application reads them');

        writeFileSync(join(directory, '.env'), 'TRILOBIT_E2E_PORT=\n');
        assert.equal(e2ePort(directory, {}), DEFAULT_PORT, 'an empty line in .env is no value');
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
});

test('a port that is not a port is refused rather than replaced by the default', async () => {
    const { e2ePort } = await checkout();
    const directory = tempDir('trilobit-e2e-port-');
    try {
        assert.throws(() => e2ePort(directory, { TRILOBIT_E2E_PORT: '181O3' }), /TRILOBIT_E2E_PORT/);
        assert.throws(() => e2ePort(directory, { TRILOBIT_E2E_PORT: '70000' }), /TRILOBIT_E2E_PORT/);

        writeFileSync(join(directory, '.env'), 'TRILOBIT_E2E_PORT=eighteen\n');
        assert.throws(() => e2ePort(directory, {}), /\.env/);
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
});
