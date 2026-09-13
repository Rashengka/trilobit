import type { Extension } from 'naja';

import { observedAgainst, scroller } from './scroller';

/**
 * c-scrollspy: the entry of the place being read carries
 * aria-current="location", and no other entry does.
 *
 * **Being read.** The part of the window somebody reads is what the theme's
 * chrome leaves free: everything under the lower edge of whatever is held
 * over the content, whose height the page measures as --layout-chrome-offset
 * (assets/chrome.ts). A place counts once its top has come up into the upper
 * quarter of that part - the reading line is a quarter of the way down it -
 * and the place being read is the last one, in the order of the page, that
 * has; before the first has, none is.
 *
 * The line is a quarter of the way down rather than at the chrome's edge
 * itself, and that is measured, not chosen for looks: a jump to a place stops
 * with its top at the edge (the scroll margin in assets/base.css), and a
 * theme that makes its bands smaller once the page has scrolled (atrium) then
 * moves the edge up - so the place followed ended up below an edge-line, and
 * the entry before it was marked. Anything the chrome shrinks by is far less
 * than a quarter of the window.
 *
 * **Watched, never listened for.** Nothing here runs on a scroll event. One
 * IntersectionObserver per contents is given as its root a band one pixel
 * tall along the reading line - the window, or whatever scroller.ts says
 * scrolls, with everything above the line and everything below the pixel
 * taken off - and it is told whenever a place starts or stops crossing the
 * band. Those are exactly the moments the answer can change: a place's top
 * goes up past the line (it begins to cross the band) or down past it (it
 * stops). The answer is worked out afresh then, from where the places are.
 *
 * The line moves with the chrome. The offset is written on the scroller as a
 * style, so a change of it is watched for there, and a window that changes
 * height moves the line too; either way the observers are made again.
 *
 * **Naja.** The observers are kept in memory, keyed by the contents they
 * belong to, and never marked in the DOM (kb-common: nette-naja/kh-0001).
 * Before a snippet is redrawn the contents in it are released and their marks
 * taken off; afterwards the contents in what came are watched, whether it came
 * from the server or from Naja's history, and every other contents is watched
 * again too - the places it leads to may have been in the snippet.
 *
 * **What it does not do.** A last place shorter than three quarters of the
 * window never reaches the line, because the page cannot scroll that far, and
 * its entry is not marked even with the page at its end. Exit condition: the
 * first page with a contents whose last part is that short.
 */

const SPY = '.c-scrollspy';
const MARK = 'aria-current';
const LOCATION = 'location';

/** How far down the part of the window the chrome leaves free the reading line is. */
const READING = 0.25;

interface Spy {
    /** Each place, with the entry leading to it, in the order of the page. */
    readonly places: readonly { readonly place: Element; readonly entry: HTMLAnchorElement }[];
    /** How far below the top of the root the reading line is. */
    readonly line: number;
    readonly observer: IntersectionObserver;
}

const spies = new Map<HTMLElement, Spy>();

/** How much of the top of the root the chrome covers, as the page last measured it. */
let offset = Number.NaN;

function measuredOffset(): number {
    const value = Number.parseFloat(getComputedStyle(scroller()).getPropertyValue('--layout-chrome-offset'));

    return Number.isNaN(value) ? 0 : value;
}

function navsIn(root: ParentNode): HTMLElement[] {
    const found = [...root.querySelectorAll<HTMLElement>(SPY)];
    if (root instanceof HTMLElement && root.matches(SPY)) {
        found.unshift(root);
    }

    return found;
}

/** Watches the places every c-scrollspy under $root leads to, where it is not watched yet. */
export function spyWithin(root: ParentNode): void {
    for (const nav of navsIn(root)) {
        if (!spies.has(nav)) {
            watch(nav);
        }
    }
}

/** Stops watching for every c-scrollspy under $root and takes its mark off. */
export function releaseSpiesWithin(root: ParentNode): void {
    for (const nav of navsIn(root)) {
        release(nav);
    }
}

/**
 * Registered before naja.initialize() and doing nothing until a snippet is
 * redrawn: the first watching is assets/app.ts's, after it.
 */
export const scrollspiesInSnippets: Extension = {
    initialize(naja): void {
        naja.snippetHandler.addEventListener('beforeUpdate', (event) => releaseSpiesWithin(event.detail.snippet));
        naja.snippetHandler.addEventListener('afterUpdate', (event) => {
            rewatchAll();
            spyWithin(event.detail.snippet);
        });
    },
};

/**
 * Follows the line the observers watch along: made again when the chrome
 * changes height or the window does. Called once, from assets/app.ts.
 */
export function followTheChrome(): void {
    offset = measuredOffset();

    new MutationObserver((): void => {
        const now = measuredOffset();
        if (now !== offset) {
            offset = now;
            rewatchAll();
        }
    }).observe(scroller(), { attributes: true, attributeFilter: ['style'] });

    window.addEventListener('resize', rewatchAll);
}

/**
 * Makes every observer again, for a line that moved. The marks stay where they
 * are meanwhile - taking them off would blink the entry, for the eye and for a
 * screen reader, every time the chrome changed size - and the new observer's
 * first report puts them right.
 */
function rewatchAll(): void {
    for (const nav of [...spies.keys()]) {
        release(nav, false);
        if (nav.isConnected) {
            watch(nav);
        }
    }
}

function watch(nav: HTMLElement): void {
    if (Number.isNaN(offset)) {
        offset = measuredOffset();
    }

    const places: { place: Element; entry: HTMLAnchorElement }[] = [];
    for (const entry of nav.querySelectorAll<HTMLAnchorElement>('a[href^="#"]')) {
        const place = placeOf(entry);
        if (place !== null) {
            places.push({ place, entry });
        }
    }
    places.sort((one, two) =>
        (one.place.compareDocumentPosition(two.place) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0 ? -1 : 1,
    );

    const root = observedAgainst();
    const height = root === null ? window.innerHeight : root.clientHeight;
    const line = offset + Math.max(0, height - offset) * READING;
    const below = Math.max(0, height - line - 1);

    const spy: Spy = {
        places,
        line,
        observer: new IntersectionObserver(() => mark(spy), {
            root,
            rootMargin: `${-line}px 0px ${-below}px 0px`,
        }),
    };

    for (const { place } of places) {
        spy.observer.observe(place);
    }
    spies.set(nav, spy);
}

/** Stops watching for $nav, and takes its mark off unless it is about to be watched again. */
function release(nav: HTMLElement, unmark = true): void {
    const spy = spies.get(nav);
    if (spy === undefined) {
        return;
    }

    spy.observer.disconnect();
    for (const { entry } of spy.places) {
        if (unmark && entry.getAttribute(MARK) === LOCATION) {
            entry.removeAttribute(MARK);
        }
    }
    spies.delete(nav);
}

function placeOf(entry: HTMLAnchorElement): Element | null {
    let id = entry.hash.slice(1);
    if (id === '' || entry.pathname !== location.pathname) {
        return null;
    }

    try {
        id = decodeURIComponent(id);
    } catch {
        // An address with a stray % is looked up as it was written.
    }

    return document.getElementById(id);
}

/** Marks the entry of the last place whose top has reached the reading line, and takes the mark off every other. */
function mark(spy: Spy): void {
    const root = observedAgainst();
    const line = (root === null ? 0 : root.getBoundingClientRect().top) + spy.line + 1;

    let reading: HTMLAnchorElement | null = null;
    for (const { place, entry } of spy.places) {
        if (!place.checkVisibility()) {
            continue;
        }
        if (place.getBoundingClientRect().top > line) {
            break;
        }
        reading = entry;
    }

    for (const { entry } of spy.places) {
        if (entry === reading) {
            entry.setAttribute(MARK, LOCATION);
        } else if (entry.getAttribute(MARK) === LOCATION) {
            entry.removeAttribute(MARK);
        }
    }
}
