import { expect, type Page } from '@playwright/test';

/**
 * Using the switch the way a person does, and waiting for the part they do not
 * see.
 *
 * The click changes the page immediately; what has to be waited for is the
 * request that writes the choice down, because everything the browser suite
 * claims about remembering happens on the next page load. Without the wait a
 * reload could outrun the write and the suite would be measuring a race.
 *
 * The status is asserted here rather than in a case of its own, so that every
 * use of the switch is also a check that the address behind it answered.
 */
export async function choose(page: Page, preference: string, value: string): Promise<void> {
    const written = page.waitForResponse(
        (response) => response.url().endsWith('/_preference') && response.request().method() === 'POST',
    );

    await page.locator(`[data-preference="${preference}"][data-preference-value="${value}"]`).click();

    expect((await written).status()).toBe(204);
}

/** What the page is drawn with, as the browser resolved the attribute. */
export async function themeOf(page: Page): Promise<string | null> {
    return page.evaluate(() => document.documentElement.getAttribute('data-theme'));
}
