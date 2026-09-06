/**
 * The shared bundle, loaded on every page regardless of module - see
 * src/Core/Presentation/Front/templates/@layout.latte's `scripts` block.
 */
import naja from 'naja';

import './app.css';

/**
 * The whole of switching a preference: the theme, the light or dark mode, and
 * whatever is added beside them.
 *
 * Every value on the page is read out of a custom property, and the properties
 * are re-declared per theme under [data-theme] - so moving one attribute on
 * <html> repaints the page and moves the navigation with it, without a request
 * and without a rebuild. See assets/themes/ledger.css.
 *
 * A control says which preference it sets and to what; nothing is named in
 * here. The attribute it moves is `data-` and the preference's name, which is
 * the same rule the server applies - see Trilobit\Core\Preference\Preference -
 * so a third preference is a control in a template and an entry in the
 * catalogue, and not a line of script.
 *
 * The choice is remembered, and the server does the remembering. The page has
 * already arrived with the right attributes on it, because whoever rendered it
 * read them off this device; what the request below adds is the other device -
 * for somebody signed in, the choice reaches their profile at the moment they
 * make it rather than whenever they next load a page.
 *
 * **Why a remembered choice does not fight the build's own configuration.** It
 * used to be the reason nothing was remembered at all: a stored preference
 * silently disagreeing with trilobit.theme is how somebody comes to report a
 * bug about a theme nobody set. Two things keep that from happening. Nothing is
 * ever stored except a deliberate choice, so a visitor who has not touched
 * these controls has nothing stored and follows the build; and a stored value
 * naming a theme the build no longer has is dropped rather than honoured, so
 * renaming or removing a theme returns its holders to configuration instead of
 * leaving them on a page with no tokens at all. Both are enforced on the
 * server, in Trilobit\Core\Preference\PreferenceCatalogue and
 * Trilobit\Core\Preference\RememberedPreferences - here because the reader of
 * this file is the one who will wonder.
 */
const PREFERENCE = 'data-preference';
const VALUE = 'data-preference-value';

document.addEventListener('click', (event: MouseEvent): void => {
    const source = event.target;
    if (!(source instanceof Element)) {
        return;
    }

    const control = source.closest(`[${PREFERENCE}]`);
    const preference = control?.getAttribute(PREFERENCE);
    const value = control?.getAttribute(VALUE);
    if (control === null || preference === null || preference === undefined || value === null || value === undefined) {
        return;
    }

    document.documentElement.setAttribute(`data-${preference}`, value);

    for (const other of document.querySelectorAll(`[${PREFERENCE}="${preference}"]`)) {
        other.setAttribute('aria-pressed', String(other === control));
    }

    void remember(preference, value);
});

/**
 * One request at a time, in the order the choices were made.
 *
 * Somebody picking a theme and then a mode within the same round trip would
 * otherwise have two writes in flight at once, and for a signed-in person both
 * would read the same profile and the second would save over the first. The
 * cookies cannot be lost that way - there is one per preference - but the
 * profile is a single row, and a lost choice there shows up on the next device
 * rather than on this screen, which is the kind nobody traces back.
 *
 * A failed request does not stop the queue: the chain is closed off after each
 * one, so the next choice is still sent.
 */
let pending: Promise<void> = Promise.resolve();

/**
 * Tells the server what was chosen.
 *
 * A failure is swallowed on purpose and it is not silence: the page in front of
 * the person has already changed, and the only thing lost is that the next one
 * will not start that way. Reverting the appearance to report it would take
 * away the thing they asked for in order to explain that it was not written
 * down.
 *
 * The address comes from the document rather than from a constant here, because
 * the router is what knows it - an installation served from a subdirectory has
 * a different one, and a path written twice is a path that can be written twice
 * differently.
 */
function remember(preference: string, value: string): Promise<void> {
    const url = document.body.getAttribute('data-preference-url');
    if (url === null || url === '') {
        return pending;
    }

    pending = pending
        .then(async () => {
            await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams({ preference, value }),
            });
        })
        // Offline, or the request was cancelled by a navigation. Either way
        // the choice on this screen stands and the next one is still sent.
        .catch(() => undefined);

    return pending;
}

naja.initialize({ history: true });
