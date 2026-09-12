import { expect, type Page } from '@playwright/test';

/**
 * Opening the menu of whoever is signed in, the way a person does, and waiting
 * until it is open.
 *
 * Everything that belongs to the person rather than to the page - who they are,
 * the switch, the way to the public site and the way out - is inside it, so a
 * suite that wants any of those has to open it first. Written once here so that
 * the testids of the trigger and of the panel are written once too.
 */
export async function openAccountMenu(page: Page): Promise<void> {
    await page.getByTestId('admin-account-menu').click();
    await expect(page.getByTestId('admin-account-menu-panel')).toBeVisible();
}
