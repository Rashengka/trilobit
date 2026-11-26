/**
 * A snippet marked `data-keep-focus` keeps the focus where it was when Naja
 * redraws it.
 *
 * Naja replaces what is inside a snippet, and the element that had the focus
 * goes with it: the focus falls to the document, and somebody moving through
 * a listing by keyboard - pressing Show, choosing the next page - starts again
 * from the top of the page after every step. So the element is remembered
 * before the snippet is replaced and found again in what replaced it, by what
 * it is rather than by where it was: its id, the name of a field, or what a
 * link or a button is called. Where it is no longer there to be found - Next,
 * on the last page, is not a link any more - the first thing in the snippet
 * that takes the focus has it.
 *
 * A name can be there more than once: a listing draws its row of pages above
 * the rows and under them, and both have a Page 2. So which of the links of
 * that name it was is remembered too, by its place among them, and the focus
 * goes back to the same row rather than to the first link of the name - which,
 * for a step taken under the list, is a page's height away.
 *
 * A snippet brought back by history.back() is left alone: the focus is not in
 * it, because going back is not something done inside the page.
 *
 * Nothing is prepared in the snippet and nothing is written into the DOM, so a
 * snippet Naja keeps for the way back is the server's markup either way.
 */
import type { Extension } from 'naja';

const MARK = 'data-keep-focus';

type Found = { id: string } | { name: string } | { label: string; nth: number };

const focusedIn = new WeakMap<Element, Found>();

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function labelOf(element: Element): string {
    return element.getAttribute('aria-label') ?? element.textContent?.trim() ?? '';
}

function namedIn(snippet: Element, label: string): HTMLElement[] {
    return [...snippet.querySelectorAll<HTMLElement>('a[href], button')].filter((element) => labelOf(element) === label);
}

function whatItIs(snippet: Element, element: Element): Found | null {
    if (element.id !== '') {
        return { id: element.id };
    }

    const name = element.getAttribute('name');
    if (name !== null && name !== '') {
        return { name };
    }

    const label = labelOf(element);

    return label === '' ? null : { label, nth: Math.max(0, namedIn(snippet, label).indexOf(element as HTMLElement)) };
}

function findIn(snippet: Element, found: Found): HTMLElement | null {
    if ('id' in found) {
        const element = document.getElementById(found.id);

        return element !== null && snippet.contains(element) ? element : null;
    }

    if ('name' in found) {
        return snippet.querySelector<HTMLElement>(`[name="${CSS.escape(found.name)}"]`);
    }

    const named = namedIn(snippet, found.label);

    return named[found.nth] ?? named[0] ?? null;
}

/** Registered before naja.initialize(), like the other extensions, and doing nothing outside a marked snippet. */
export const focusThroughRedraws: Extension = {
    initialize(naja): void {
        naja.snippetHandler.addEventListener('beforeUpdate', (event) => {
            const { snippet } = event.detail;
            const focused = document.activeElement;
            if (!snippet.hasAttribute(MARK) || focused === null || focused === snippet || !snippet.contains(focused)) {
                return;
            }

            const found = whatItIs(snippet, focused);
            if (found !== null) {
                focusedIn.set(snippet, found);
            }
        });

        naja.snippetHandler.addEventListener('afterUpdate', (event) => {
            const { snippet, fromCache } = event.detail;
            const found = focusedIn.get(snippet);
            focusedIn.delete(snippet);
            if (found === undefined || fromCache) {
                return;
            }

            const target = findIn(snippet, found) ?? snippet.querySelector<HTMLElement>(FOCUSABLE);
            target?.focus({ preventScroll: true });
        });
    },
};
