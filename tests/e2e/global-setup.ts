import type { FullConfig } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import { assertServerIsThisCheckout } from './checkout.mjs';

/**
 * Runs once, after Playwright has started the server or taken over one that was
 * already answering, and before any spec: the server has to prove it is this
 * checkout's (see tests/e2e/checkout.mjs). A server Playwright started itself
 * proves it as well, which keeps the check honest - a check that only ever runs
 * against strangers would never be seen passing.
 */
export default async function globalSetup(config: FullConfig): Promise<void> {
    const url = config.webServer?.url;
    if (url === undefined) {
        throw new Error('playwright.config.ts names no webServer.url to ask who the server is.');
    }

    await assertServerIsThisCheckout(url, fileURLToPath(new URL('../..', import.meta.url)));
}
