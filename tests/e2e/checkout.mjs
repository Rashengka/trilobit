// Which port the browser suite's server listens on, and how the suite tells a
// server of this checkout from a server of another one.
//
// Plain JavaScript rather than TypeScript, so that Node's own test runner can
// import it as it is (tests/frontend/e2e-server.test.mjs); playwright.config.ts
// and tests/e2e/global-setup.ts import it too.

import { createHash } from 'node:crypto';
import { readFileSync, realpathSync } from 'node:fs';
import { join } from 'node:path';

/** The port of a checkout that names none: the main one. A worktree names its own. */
export const DEFAULT_PORT = 18100;

/**
 * The path tests/e2e/checkout-identity.php answers on. Nothing else in the
 * application has it, and nothing but the suite's own server answers it.
 */
export const IDENTITY_PATH = '/__trilobit-e2e-checkout';

/**
 * The port for the checkout in $root: TRILOBIT_E2E_PORT from the process
 * environment, else from the checkout's .env, else DEFAULT_PORT.
 *
 * The environment wins for the same reason it wins in the application
 * (Trilobit\Core\Config\Environment): it is the choice made for this one run -
 * a build server, a shell that wants two runs side by side - while .env is what
 * the checkout stands on the rest of the time. .env is what gives each checkout
 * a port of its own without anybody having to remember to export one, which is
 * the whole point: a forgotten variable used to mean the shared default, and
 * the shared default is where one checkout meets another's server.
 *
 * Empty counts as unset at either level, as it does in .env.example. Unlike the
 * application, an empty variable in the environment falls through to .env
 * rather than to the default - `TRILOBIT_E2E_PORT= npm run e2e` must not move a
 * worktree back onto the port every checkout shares.
 *
 * A value that is not a port is refused rather than replaced by the default,
 * for the same reason: a typo would otherwise land on the shared port in
 * silence.
 */
export function e2ePort(root, env = process.env) {
    const fromEnvironment = (env.TRILOBIT_E2E_PORT ?? '').trim();
    const fromFile = readEnvFile(join(root, '.env')).TRILOBIT_E2E_PORT ?? '';

    let value = String(DEFAULT_PORT);
    let source = 'the default';
    if (fromEnvironment !== '') {
        value = fromEnvironment;
        source = 'the environment';
    } else if (fromFile !== '') {
        value = fromFile;
        source = '.env';
    }

    const port = /^\d+$/.test(value) ? Number(value) : NaN;
    if (!(port >= 1 && port <= 65535)) {
        throw new Error(`TRILOBIT_E2E_PORT from ${source} is "${value}", which is not a port.`);
    }

    return port;
}

/**
 * What the suite's server of the checkout in $root answers on IDENTITY_PATH:
 * the SHA-256 of the checkout's real path.
 *
 * A hash rather than the path, because the answer goes to whatever can reach
 * the port, and a directory on a developer's disk - a user name, a client's
 * name in a folder - is not something a server should tell. Equality is all
 * the suite needs, and a hash keeps exactly that.
 */
export function checkoutIdentity(root) {
    return createHash('sha256').update(realpathSync(root)).digest('hex');
}

/**
 * Throws unless the server at $baseURL is the suite's server of the checkout in
 * $root.
 *
 * Playwright takes a server that already answers on the port over without
 * asking whose it is (reuseExistingServer, on a developer's machine). This asks.
 * A server from any other checkout - one with this check, one from before it,
 * or anything else listening there - answers something other than this
 * checkout's identity, and the run stops before a single spec is run against
 * code and a database that are not the ones the specs prepare.
 */
export async function assertServerIsThisCheckout(baseURL, root) {
    const expected = checkoutIdentity(root);
    const port = new URL(baseURL).port;

    let answer;
    try {
        const response = await fetch(new URL(IDENTITY_PATH, baseURL), {
            redirect: 'manual',
            signal: AbortSignal.timeout(10_000),
        });
        const body = (await response.text()).trim();
        if (body === expected) {
            return;
        }
        answer = describe(response.status, body);
    } catch (error) {
        answer = `no answer (${error.message})`;
    }

    throw new Error([
        `The server on port ${port} is not this checkout's: asked who it is at ${IDENTITY_PATH}, it gave ${answer}.`,
        `It is most likely a server another checkout (a worktree) left running, and this checkout's specs`,
        `would run against that checkout's code and database. Stop that server, or give this checkout a port`,
        `of its own: TRILOBIT_E2E_PORT in its .env, or in the environment for one run.`,
    ].join('\n'));
}

/** What the server said, told without repeating a page it may have sent. */
function describe(status, body) {
    if (/^[0-9a-f]{64}$/.test(body)) {
        return 'the identity of another checkout';
    }
    if (status !== 200) {
        return `status ${status}`;
    }

    return 'a page instead of an identity (a checkout from before this check, a server outside dev, or no Trilobit at all)';
}

/**
 * .env read the way Trilobit\Core\Config\Environment reads it: one NAME=value
 * per line, # starts a comment line, spaces around both are trimmed and one
 * pair of matching quotes is taken off. No more, so that the two cannot read
 * the same file differently.
 */
function readEnvFile(path) {
    let contents;
    try {
        contents = readFileSync(path, 'utf8');
    } catch (error) {
        if (error.code === 'ENOENT') {
            return {};
        }
        throw error;
    }

    const values = {};
    for (const raw of contents.split(/\r\n|\r|\n/)) {
        const line = raw.trim();
        const separator = line.indexOf('=');
        if (line === '' || line.startsWith('#') || separator === -1) {
            continue;
        }
        const name = line.slice(0, separator).trim();
        if (name !== '') {
            values[name] = unquote(line.slice(separator + 1).trim());
        }
    }

    return values;
}

function unquote(value) {
    const quote = value[0];
    if (value.length >= 2 && (quote === '"' || quote === "'") && value.endsWith(quote)) {
        return value.slice(1, -1);
    }

    return value;
}
