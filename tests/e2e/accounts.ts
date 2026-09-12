import { test } from '@playwright/test';

/**
 * The address a spec's own account signs in with, for the copy of the spec
 * that is running.
 *
 * A spec makes its account in beforeAll, and `app:account` run again for an
 * address that is already there gives that account a new password. Run once, a
 * spec is the only one using its address, and that is all it needs. Run as
 * several copies at once - `--repeat-each`, which is how a race is hunted -
 * every copy would share the one account: each would replace the password the
 * others had just read, and two making it at the same moment would both insert
 * it. So each copy after the first gets an address of its own, and the first
 * keeps the one it has always had.
 *
 * It can only be asked while a spec runs - in a hook or a test - because that
 * is when Playwright knows which copy it is.
 */
export function addressFor(base: string): string {
    const copy = test.info().repeatEachIndex;
    if (copy === 0) {
        return base;
    }

    const at = base.lastIndexOf('@');

    return `${base.slice(0, at)}-copy${copy}${base.slice(at)}`;
}
