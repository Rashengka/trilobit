import { fileURLToPath } from 'node:url';

import { checkPaths, REQUEST_VARIABLE } from './matrix.mjs';

/** What bin/snapshots.mjs asks for, as it reaches the spec through the environment. */
export interface SnapshotRequest {
    /** The paths to picture, already checked by bin/snapshots.mjs and checked again here. */
    readonly paths: readonly string[];
    /** Whether to sign in to the administration first. */
    readonly signIn: boolean;
    /** A selector to click before each picture, or null. */
    readonly click: string | null;
    /** The directory the pictures and their results go to. */
    readonly out: string;
}

/**
 * The signed-in browser state the global setup leaves for the spec. Under
 * var/tmp, which bin/e2e-docker.mjs mounts as a tmpfs, so the session cookie
 * never reaches the checkout on the host.
 */
export const SESSION_FILE = fileURLToPath(new URL('../../var/tmp/snapshot-session.json', import.meta.url));

/**
 * The request, or an error saying how to make one. Run without
 * bin/snapshots.mjs - `npx playwright test -c playwright.snapshots.config.ts`
 * on its own - there is none, and a run that pictured nothing must not end as
 * a green one.
 */
export function readRequest(): SnapshotRequest {
    const raw = process.env[REQUEST_VARIABLE];
    if (raw === undefined || raw === '') {
        throw new Error(`${REQUEST_VARIABLE} is not set; run the matrix with \`npm run snapshots -- <paths>\`.`);
    }

    const parsed = JSON.parse(raw) as Partial<SnapshotRequest>;
    if (!Array.isArray(parsed.paths) || typeof parsed.out !== 'string' || parsed.out === '') {
        throw new Error(`${REQUEST_VARIABLE} does not name paths and a directory to save to.`);
    }

    return {
        paths: checkPaths(parsed.paths.map(String)),
        signIn: parsed.signIn === true,
        click: typeof parsed.click === 'string' && parsed.click !== '' ? parsed.click : null,
        out: parsed.out,
    };
}
