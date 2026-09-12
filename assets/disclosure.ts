/**
 * An address naming a folded disclosure opens it.
 *
 * c-collapse and the items of c-accordion are <details>, and the browser
 * already opens one for an address naming something *inside* it - that is part
 * of the element. What it does not do is open one for an address naming the
 * disclosure itself: its summary is in view, so as far as the browser is
 * concerned there is nothing hidden to reveal. Yet that is the address somebody
 * shares - "the question about moulting" - and landing on its closed title is
 * landing on a page that did not do what the link said. So this opens it, and
 * this is all the script there is for either component.
 *
 * Opening it through the element's own `open` is what the browser would do on
 * a click, so a disclosure sharing a name with others still closes them.
 *
 * Three moments an address can name one: the page arriving with it, the
 * address changing on the page, and a link to the address the page already has
 * being followed again - which changes nothing the browser announces, and
 * which, after the item was closed by hand, has to open it all the same.
 *
 * **Nothing here is held by an element, so a Naja redraw has nothing to clean
 * up.** Both listeners sit on the window and the document, which a redraw
 * does not replace, and they look the element up at the moment they are asked.
 * A redraw is also not a navigation: the address it leaves behind is the one
 * the page already had, and opening the named item again on every redraw would
 * reopen something the reader had just closed.
 *
 * Opening is the only direction. Recording every opened item in the address
 * would put an entry in the history for each click, and Back would then step
 * through the items instead of leaving the page.
 */

/** What an address may open: the components drawn as <details>. */
const OPENED_BY_ADDRESS = 'details.c-collapse';

function named(): HTMLDetailsElement | null {
    let id = location.hash.slice(1);
    if (id === '') {
        return null;
    }

    try {
        id = decodeURIComponent(id);
    } catch {
        // An address with a stray % is looked up as it was written.
    }

    const target = document.getElementById(id);

    return target instanceof HTMLDetailsElement && target.matches(OPENED_BY_ADDRESS) ? target : null;
}

function openTheNamedOne(): void {
    const details = named();
    if (details !== null && !details.open) {
        details.open = true;
    }
}

/** A link to exactly the address the page is at, which the browser follows without announcing it. */
function leadsHere(link: HTMLAnchorElement): boolean {
    return link.hash !== ''
        && link.hash === location.hash
        && link.origin === location.origin
        && link.pathname === location.pathname
        && link.search === location.search;
}

export function openWhatTheAddressNames(): void {
    openTheNamedOne();

    window.addEventListener('hashchange', openTheNamedOne);

    document.addEventListener('click', (event: MouseEvent): void => {
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (link instanceof HTMLAnchorElement && leadsHere(link)) {
            openTheNamedOne();
        }
    });
}
