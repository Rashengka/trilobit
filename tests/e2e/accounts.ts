import { test } from '@playwright/test';

/**
 * The address a spec's own account signs in with, for the copy of the spec
 * that is running.
 *
 * A spec makes its account in beforeAll, and `app:account` run again for an
 * address that is already there gives that account a new password. Run once, a
 * spec is the only one using its address, and that is all it needs. But a spec
 * is often run as several copies at once: one per browser when more than one is
 * asked for (`PLAYWRIGHT_BROWSERS=all`), and several per browser under
 * `--repeat-each`, which is how a race is hunted - and with fullyParallel all of
 * them run side by side. Sharing the one account, each copy would replace the
 * password the others had just read and be refused or signed out, and two
 * making it at the same moment would both insert it. So a copy is told apart
 * by both the browser it runs in and its repeat, and every copy but the first
 * gets an address of its own.
 *
 * The first - the first browser the configuration lists, first repeat - keeps
 * the address it has always had: that is the whole of an ordinary run, in
 * Chromium alone, so neither the build server nor a developer's database sees
 * a different account than before.
 *
 * A browser is named in the address by the name of its project, which is why
 * that name has to be one an address can carry unchanged. Anything else is
 * refused rather than rewritten: two names rewritten to the same word would
 * share an account again, and that would look like a flaky test, not like this.
 *
 * It can only be asked while a spec runs - in a hook or a test - because that
 * is when Playwright knows which copy it is.
 */
export function addressFor(base: string): string {
    const info = test.info();
    const browser = info.project.name;
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(browser)) {
        throw new Error(`The project "${browser}" cannot be named in an address; name it in lower-case letters, digits and hyphens.`);
    }

    const marks: string[] = [];
    if (browser !== info.config.projects[0]?.name) {
        marks.push(browser);
    }
    if (info.repeatEachIndex !== 0) {
        marks.push(`copy${info.repeatEachIndex}`);
    }
    if (marks.length === 0) {
        return base;
    }

    const at = base.lastIndexOf('@');

    return `${base.slice(0, at)}-${marks.join('-')}${base.slice(at)}`;
}
