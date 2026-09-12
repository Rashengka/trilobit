import type { Extension } from 'naja';

/**
 * c-tabs: the tabs laid over what the server drew, which is every panel one
 * under another, each under its title (src/Core/Presentation/components/tabs.latte).
 *
 * The pattern is the ARIA Authoring Practices' Tabs. A tab list, named after
 * the component, goes in front of the panels, with one tab for each panel,
 * named after the panel's title; the title itself is then hidden, so that it
 * is not read twice. Only the selected tab is in the order of the Tab key
 * (a roving tabindex); the arrow keys move between the tabs, round from the
 * last to the first, and Home and End go to the ends. Tab leaves the list for
 * the panel it shows, which takes the focus itself when there is nothing in
 * it to focus.
 *
 * **Activation is automatic: a tab the arrow keys arrive on is shown at
 * once.** The Practices leave the choice to how long showing a panel takes,
 * and here it takes nothing - every panel is already in the page, drawn by the
 * server - so making somebody press Enter on each one before seeing it would
 * only be a second key for the same thing.
 *
 * **The address.** An address naming a panel, or something inside one, shows
 * that panel - on arriving, when the address changes, and when a link to the
 * address the page is already at is followed again. Choosing a tab does not
 * write the address: every choice would be an entry in the history, and Back
 * would then step through the tabs instead of leaving the page. A panel is
 * linked to by its id, which the caller gives when a panel should be.
 *
 * **Naja.** What is laid over the markup is kept in memory, on the root, in a
 * WeakMap - never as a mark in the DOM. Naja keeps the server's markup of a
 * snippet for the way back through history, and a mark copied in with it
 * would claim tabs that are no longer there (kb-common: nette-naja/kh-0001).
 * The first laying over waits for naja.initialize() for the same reason (see
 * assets/app.ts), so that what Naja keeps is the server's markup and not ours.
 * Before a snippet is redrawn the tabs in it are taken down, the root the
 * snippet may itself be included, and afterwards laid over what came -
 * whether it came from the server or from Naja's history. The tab that was
 * chosen stays chosen when the panel it names comes back with the same id:
 * a redraw is typically a form in that very panel, refused, and showing the
 * first tab instead would hide the reason.
 *
 * Every event is listened for on the document, so there is nothing per
 * component to take down but the markup.
 */

const TABS = '.c-tabs';
const PANEL = 'c-tabs__panel';
const TITLE = 'c-tabs__title';
const TAB = 'c-tabs__tab';
const LABEL = 'data-tabs-label';

/** What takes the focus by the keyboard, for deciding whether a panel has to take it itself. */
const FOCUSABLE = [
    'a[href]',
    'button:not(:disabled)',
    'input:not([type="hidden"]):not(:disabled)',
    'select:not(:disabled)',
    'textarea:not(:disabled)',
    'summary',
    '[tabindex]:not([tabindex="-1"])',
].join(', ');

interface Laid {
    readonly list: HTMLElement;
    readonly tabs: readonly HTMLButtonElement[];
    readonly panels: readonly HTMLElement[];
    readonly titles: readonly (HTMLElement | null)[];
    /** The panels given an id here, which the server had not given one. */
    readonly named: readonly HTMLElement[];
}

const laid = new WeakMap<Element, Laid>();

/** Kept from Naja's beforeUpdate to its afterUpdate of the same snippet: the ids of the panels that were shown. */
const shownIn = new WeakMap<Element, readonly string[]>();

let made = 0;

function rootsIn(root: ParentNode): HTMLElement[] {
    const found = [...root.querySelectorAll<HTMLElement>(TABS)];
    if (root instanceof HTMLElement && root.matches(TABS)) {
        found.unshift(root);
    }

    return found;
}

function childrenOf(element: Element, className: string): HTMLElement[] {
    return [...element.children].filter(
        (child): child is HTMLElement => child instanceof HTMLElement && child.classList.contains(className),
    );
}

/** Lays tabs over every c-tabs under $root that has none yet, showing a panel whose id is in $shown where there is one. */
export function layTabsWithin(root: ParentNode, shown: readonly string[] = []): void {
    for (const element of rootsIn(root)) {
        lay(element, shown);
    }
}

/** Takes the tabs under $root down again, leaving the markup as the server drew it. */
export function takeTabsDownWithin(root: ParentNode): void {
    for (const element of rootsIn(root)) {
        takeDown(element);
    }
}

/**
 * Registered before naja.initialize() and doing nothing until a snippet is
 * redrawn: the first laying over is assets/app.ts's, after it.
 */
export const tabsInSnippets: Extension = {
    initialize(naja): void {
        naja.snippetHandler.addEventListener('beforeUpdate', (event) => {
            const { snippet } = event.detail;
            const shown: string[] = [];
            for (const element of rootsIn(snippet)) {
                const state = laid.get(element);
                const panel = state?.panels.find((candidate) => !candidate.hidden);
                if (state !== undefined && panel !== undefined && !state.named.includes(panel)) {
                    shown.push(panel.id);
                }
                takeDown(element);
            }
            shownIn.set(snippet, shown);
        });

        naja.snippetHandler.addEventListener('afterUpdate', (event) => {
            const { snippet } = event.detail;
            layTabsWithin(snippet, shownIn.get(snippet) ?? []);
            shownIn.delete(snippet);
        });
    },
};

function lay(root: HTMLElement, shown: readonly string[]): void {
    if (laid.has(root)) {
        return;
    }

    const panels = childrenOf(root, PANEL);
    if (panels.length === 0) {
        return;
    }

    const list = document.createElement('div');
    list.className = 'c-tabs__list';
    list.setAttribute('role', 'tablist');
    const label = root.getAttribute(LABEL);
    if (label !== null && label !== '') {
        list.setAttribute('aria-label', label);
    }

    made++;
    const named: HTMLElement[] = [];
    const titles: (HTMLElement | null)[] = [];
    const tabs = panels.map((panel, index) => {
        if (panel.id === '') {
            panel.id = `c-tabs-${made}-${index + 1}`;
            named.push(panel);
        }

        const title = childrenOf(panel, TITLE)[0] ?? null;
        titles.push(title);

        const tab = document.createElement('button');
        tab.type = 'button';
        tab.className = TAB;
        tab.id = `${panel.id}-tab`;
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-controls', panel.id);
        tab.textContent = title?.textContent?.trim() || `${index + 1}`;
        list.append(tab);

        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', tab.id);
        if (panel.querySelector(FOCUSABLE) === null) {
            panel.tabIndex = 0;
        }
        if (title !== null) {
            title.hidden = true;
        }

        return tab;
    });

    root.prepend(list);

    const state: Laid = { list, tabs, panels, titles, named };
    laid.set(root, state);

    const target = addressed();
    const remembered = panels.findIndex((panel) => shown.includes(panel.id));
    const byAddress = panels.findIndex((panel) => panel.contains(target));
    select(state, remembered >= 0 ? remembered : byAddress >= 0 ? byAddress : 0);
}

function takeDown(root: Element): void {
    const state = laid.get(root);
    if (state === undefined) {
        return;
    }

    state.list.remove();
    for (const panel of state.panels) {
        panel.removeAttribute('role');
        panel.removeAttribute('aria-labelledby');
        panel.removeAttribute('tabindex');
        panel.hidden = false;
    }
    for (const title of state.titles) {
        if (title !== null) {
            title.hidden = false;
        }
    }
    for (const panel of state.named) {
        panel.removeAttribute('id');
    }

    laid.delete(root);
}

function select(state: Laid, index: number, focus = false): void {
    state.tabs.forEach((tab, at) => {
        const chosen = at === index;
        tab.setAttribute('aria-selected', String(chosen));
        tab.tabIndex = chosen ? 0 : -1;
        const panel = state.panels[at];
        if (panel !== undefined) {
            panel.hidden = !chosen;
        }
    });

    if (focus) {
        state.tabs[index]?.focus();
    }
}

/** The tab $element is, with the tabs it belongs to, when they are laid. */
function tabOf(element: EventTarget | null): { state: Laid; index: number } | null {
    const tab = element instanceof Element ? element.closest(`.${TAB}`) : null;
    const root = tab?.parentElement?.parentElement;
    const state = root === null || root === undefined ? undefined : laid.get(root);
    const index = state?.tabs.indexOf(tab as HTMLButtonElement) ?? -1;

    return state === undefined || index < 0 ? null : { state, index };
}

/** What the address names, when it names something. */
function addressed(): Element | null {
    let id = location.hash.slice(1);
    if (id === '') {
        return null;
    }

    try {
        id = decodeURIComponent(id);
    } catch {
        // An address with a stray % is looked up as it was written.
    }

    return document.getElementById(id);
}

/** Shows every panel the address names or holds what it names, from the outermost in, and brings it into view. */
function showWhatTheAddressNames(): void {
    const target = addressed();
    if (target === null) {
        return;
    }

    let changed = false;
    const enclosing: Element[] = [];
    for (let at: Element | null = target; at !== null; at = at.parentElement) {
        if (at.classList.contains(PANEL)) {
            enclosing.unshift(at);
        }
    }

    for (const panel of enclosing) {
        const root = panel.parentElement;
        const state = root === null ? undefined : laid.get(root);
        const index = state?.panels.indexOf(panel as HTMLElement) ?? -1;
        if (state !== undefined && index >= 0 && panel instanceof HTMLElement && panel.hidden) {
            select(state, index);
            changed = true;
        }
    }

    if (changed) {
        target.scrollIntoView();
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

/** The keys of the pattern, and which tab each leads to from $index of $count. */
function arrival(key: string, index: number, count: number, rightToLeft: boolean): number | null {
    const forward = rightToLeft ? 'ArrowLeft' : 'ArrowRight';
    const backward = rightToLeft ? 'ArrowRight' : 'ArrowLeft';

    switch (key) {
        case forward:
            return (index + 1) % count;
        case backward:
            return (index - 1 + count) % count;
        case 'Home':
            return 0;
        case 'End':
            return count - 1;
        default:
            return null;
    }
}

/**
 * Listens on the document for everything the tabs answer: a click on a tab,
 * the keys on one, and the address. Called once, from assets/app.ts.
 */
export function switchTheTabs(): void {
    document.addEventListener('click', (event: MouseEvent): void => {
        const found = tabOf(event.target);
        if (found !== null) {
            select(found.state, found.index);

            return;
        }

        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (link instanceof HTMLAnchorElement && leadsHere(link)) {
            showWhatTheAddressNames();
        }
    });

    document.addEventListener('keydown', (event: KeyboardEvent): void => {
        if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
            return;
        }

        const found = tabOf(event.target);
        if (found === null) {
            return;
        }

        const rightToLeft = getComputedStyle(found.state.list).direction === 'rtl';
        const next = arrival(event.key, found.index, found.state.tabs.length, rightToLeft);
        if (next === null) {
            return;
        }

        event.preventDefault();
        select(found.state, next, true);
    });

    window.addEventListener('hashchange', showWhatTheAddressNames);
}
