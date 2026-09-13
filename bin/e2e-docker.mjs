#!/usr/bin/env node
//
// e2e-docker - runs the browser suite on Linux, in Playwright's own image.
//
//   node bin/e2e-docker.mjs [--browser=chromium|firefox|all] [playwright test arguments]
//   npm run e2e:docker -- --browser=all --workers=2
//
// `npm run e2e` drives the Google Chrome installed on the machine it runs on.
// On a Mac that hides a class of fault the build server sees: macOS draws
// overlay scrollbars that take no room, Linux draws a classic one fifteen
// pixels wide, and a layout that forgot about it looks right on one and is
// off by those fifteen pixels on the other. The same goes for fonts and for
// the headless build of Chromium CI uses. This runs the same suite where CI
// runs it, and in Firefox as well when asked.
//
// The suite runs unchanged: playwright.config.ts starts PHP's server, and the
// specs that make accounts run bin/trilobit, inside the container. So the image
// is Playwright's with PHP added (docker/e2e/Dockerfile), and the container
// joins the network of this checkout's database, where that database answers
// under its service name. The server listens on the container's own loopback,
// which no other checkout and nothing on the host shares - a server another
// checkout left running cannot be picked up by mistake.
//
// What it keeps, by name:
//   image   trilobit-e2e:<version>              Playwright's image plus PHP
//   volume  <compose project>-e2e-node-modules  Linux node_modules for this checkout
// and a container named <compose project>-e2e, removed when the run ends.
//
// Exit codes: whatever `playwright test` exits with, or 2 when the run could
// not be set up at all.
//

import { execFileSync, spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('..', import.meta.url)).replace(/\/$/, '');

const BROWSERS = ['chromium', 'firefox', 'all'];

function fail(message) {
    process.stderr.write(`e2e-docker: ${message}\n`);
    process.exit(2);
}

function docker(args) {
    return execFileSync('docker', args, { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] }).trim();
}

/**
 * The version the browsers have to be of is the version of the test runner npm
 * installs, and that is the one in the lock file - package.json could name a
 * range. Read from here so that it is written down in one place only.
 */
function playwrightVersion() {
    const lock = JSON.parse(readFileSync(join(ROOT, 'package-lock.json'), 'utf8'));
    const version = lock.packages?.['node_modules/@playwright/test']?.version;
    if (typeof version !== 'string' || !/^\d+\.\d+\.\d+$/.test(version)) {
        fail('package-lock.json does not say which @playwright/test it installs.');
    }

    return version;
}

/**
 * This checkout's database container, and the network and compose project it
 * belongs to. Asked of Compose from the checkout's own directory, so that a
 * worktree finds its own stack and never the one next to it.
 */
function database() {
    const id = docker(['compose', 'ps', '--quiet', 'database']);
    if (id === '') {
        fail('the database of this checkout is not running; start it with `docker compose up -d database`.');
    }

    const project = docker(['inspect', '--format', '{{index .Config.Labels "com.docker.compose.project"}}', id]);
    const networks = docker(['inspect', '--format', '{{range $name, $_ := .NetworkSettings.Networks}}{{println $name}}{{end}}', id])
        .split('\n')
        .map((name) => name.trim())
        .filter((name) => name !== '');
    if (project === '' || networks.length !== 1) {
        fail(`cannot tell which network the database container ${id} is on (${networks.join(', ') || 'none'}).`);
    }

    return { project, network: networks[0] };
}

const passed = [];
let browser = 'chromium';
for (const argument of process.argv.slice(2)) {
    const match = /^--browser=(.*)$/.exec(argument);
    if (match === null) {
        passed.push(argument);
        continue;
    }
    if (!BROWSERS.includes(match[1])) {
        fail(`--browser takes ${BROWSERS.join(', ')}; "${match[1]}" is none of them.`);
    }
    browser = match[1];
}

const version = playwrightVersion();
const image = `trilobit-e2e:${version}`;
const { project, network } = database();
const container = `${project}-e2e`;
const volume = `${project}-e2e-node-modules`;

// Built every time rather than when missing: with the layers cached it takes a
// second, and an image kept from before a change to the Dockerfile would run
// the suite on something nobody wrote down any more.
docker(['build', '--quiet', '--build-arg', `PLAYWRIGHT_VERSION=${version}`, '--tag', image, join(ROOT, 'docker', 'e2e')]);

// The inside of the container, as one shell line.
//
// node_modules is a volume of its own laid over the checkout's, never the
// checkout's own: the one on the host was installed for macOS, and some of what
// is in it (the bundler, Tailwind's engine) is native code for that system.
// Run from Linux, it would fail; installed into from Linux, it would break
// `npm run build` on the host. The volume is installed into only when the lock
// file has changed since the last install, which the stamp inside it records.
const inside = [
    'set -e',
    'stamp=node_modules/.trilobit-e2e-lock',
    'wanted=$(sha256sum package-lock.json | cut -d" " -f1)',
    'if [ "$(cat "$stamp" 2>/dev/null)" != "$wanted" ]; then npm ci --no-audit --no-fund && echo "$wanted" > "$stamp"; fi',
    'exec npx playwright test "$@"',
].join('\n');

const run = [
    'run', '--rm', '--init',
    '--name', container,
    '--network', network,
    // The checkout at the same path it has on the host, so that a path in a
    // trace, an error page or a compiled container means the same on both.
    '--volume', `${ROOT}:${ROOT}`,
    '--volume', `${volume}:${ROOT}/node_modules`,
    // The compiled container and the sessions stay in the container. The host
    // is running the same checkout against the database on its published port;
    // a cache written here would be read there.
    '--tmpfs', `${ROOT}/var/tmp`,
    '--workdir', ROOT,
    // Chromium keeps its shared memory in /dev/shm, which Docker makes 64 MB,
    // and a page past that crashes the tab rather than failing a test.
    '--shm-size', '1g',
    // Inside the network the database is the service, on the port it listens on.
    // The rest of what the application reads comes from the checkout's .env, as
    // it does on the host.
    '--env', 'TRILOBIT_DB_HOST=database',
    '--env', 'TRILOBIT_DB_PORT=3306',
    // Playwright's own Chromium, as on the build server: there is no Google
    // Chrome in the image to use as a channel.
    '--env', 'PLAYWRIGHT_CHANNEL=',
    '--env', `PLAYWRIGHT_BROWSERS=${browser}`,
    ...(process.stdout.isTTY ? ['--tty'] : []),
    image,
    'sh', '-c', inside, 'e2e-docker', ...passed,
];

process.stdout.write(`e2e-docker: ${image} on ${network}, ${browser}, node_modules in ${volume}\n`);

const child = spawn('docker', run, { cwd: ROOT, stdio: 'inherit' });

// --init and Docker's own signal proxy take an interrupt to the processes in
// the container; the container is then removed by --rm. Stopping it by name is
// for the case where this process is killed before Docker could pass that on.
for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => {
        spawn('docker', ['stop', container], { stdio: 'inherit' });
    });
}

child.on('exit', (code, signal) => {
    process.exit(code ?? (signal === null ? 2 : 1));
});
