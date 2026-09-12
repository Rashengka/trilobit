/**
 * The entries under an entry of c-nav, opened and closed.
 *
 * The state is one attribute: aria-expanded on the button beside the entry,
 * which the stylesheet hides the list by and a screen reader reads. Nothing
 * else here keeps a copy of it.
 *
 * **Which way the entries open is the theme's, and it is read rather than
 * named.** A theme that unfolds the entries in place (ledger) leaves the list in
 * the flow of the page; a theme that opens them as a block over it (atrium)
 * positions the list out of the flow, through --layout-nav-sub-position. This
 * file asks the browser which of the two the list ended up as - the same way
 * assets/chrome.ts asks what is held - so there is no second switch that could
 * be set to disagree with the first, and a switch of theme is followed at once.
 *
 * Unfolded in place, the tree is folded by hand: the button opens and closes,
 * and nothing else does.
 *
 * A block over the page covers something, so it also opens where the pointer
 * rests and it has to know when to go away. It goes when:
 *
 *   - the mouse leaves the entry and the block, after a moment's grace, so that
 *     the gap between the row and the block can be crossed;
 *   - Escape is pressed;
 *   - a pointer goes down anywhere outside it;
 *   - the focus leaves it for something outside.
 *
 * Only the mouse hovers. A finger and a pen report pointer events too, but on a
 * touch screen "resting on it" is a tap that also clicks, and the block would
 * open and close again under the one touch. For them - and for the keyboard -
 * the button is the way in, and it is an equal one rather than a fallback
 * (.ai/plans/10-menu-submenu-a-rozcestniky.md, M1).
 *
 * The one thing a mouse click on the button does not do is shut a block the
 * same mouse opened by resting on the entry: the pointer is on its way to the
 * button through the entry, so the block is already open by the time the click
 * lands, and closing it then would read as the button not working.
 *
 * Wherever a block closes with the focus inside it, the focus goes back to the
 * button that opened it, rather than staying on a link that is no longer drawn.
 *
 * Everything is listened for on the document, so an entry drawn later - a
 * snippet Naja redraws - works without being found and set up first.
 */

const ENTRY = '.c-nav__item';
const BUTTON = '.c-nav__toggle';
const LIST = '.c-nav__sub';

/** How long the mouse may be away from an open block before it closes. */
const GRACE_MS = 250;

/** The kind of pointer that went down last, so a click can say where it came from. */
let lastPointer = '';

const leaving = new Map<Element, number>();

export function unfoldTheNavigation(): void {
    document.addEventListener(
        'pointerdown',
        (event: PointerEvent): void => {
            lastPointer = event.pointerType;

            for (const entry of openBlocks()) {
                if (!(event.target instanceof Node && entry.contains(event.target))) {
                    setOpen(entry, false);
                }
            }
        },
        { capture: true },
    );

    document.addEventListener('click', (event: MouseEvent): void => {
        const button = event.target instanceof Element ? event.target.closest(BUTTON) : null;
        const entry = button?.parentElement;
        if (button === null || button === undefined || entry === null || entry === undefined) {
            return;
        }

        // A click the keyboard made carries no count of presses.
        const byMouse = event.detail > 0 && lastPointer === 'mouse';
        if (byMouse && isOpen(entry) && isBlock(entry)) {
            return;
        }

        setOpen(entry, !isOpen(entry));
    });

    document.addEventListener('pointerover', (event: PointerEvent): void => {
        if (event.pointerType !== 'mouse') {
            return;
        }

        const entry = blockEntryOf(event.target);
        if (entry === null) {
            return;
        }

        stayOpen(entry);
        if (!isOpen(entry)) {
            setOpen(entry, true);
        }
    });

    document.addEventListener('pointerout', (event: PointerEvent): void => {
        if (event.pointerType !== 'mouse') {
            return;
        }

        const entry = blockEntryOf(event.target);
        if (entry === null || !isOpen(entry)) {
            return;
        }

        if (event.relatedTarget instanceof Node && entry.contains(event.relatedTarget)) {
            return;
        }

        stayOpen(entry);
        leaving.set(
            entry,
            window.setTimeout(() => {
                leaving.delete(entry);
                setOpen(entry, false);
            }, GRACE_MS),
        );
    });

    document.addEventListener('keydown', (event: KeyboardEvent): void => {
        if (event.key !== 'Escape') {
            return;
        }

        for (const entry of openBlocks()) {
            setOpen(entry, false);
        }
    });

    document.addEventListener('focusout', (event: FocusEvent): void => {
        const entry = blockEntryOf(event.target);
        if (entry === null || !isOpen(entry)) {
            return;
        }

        const next = event.relatedTarget;
        if (next instanceof Node) {
            if (!entry.contains(next)) {
                setOpen(entry, false);
            }

            return;
        }

        // The focus went to nothing in particular: a press on something that
        // takes no focus, which may be the inside of the block itself, or the
        // window losing it. Which of them it was is only known once it settles.
        window.setTimeout(() => {
            if (!entry.contains(document.activeElement) && !entry.matches(':hover')) {
                setOpen(entry, false);
            }
        }, 0);
    });
}

function setOpen(entry: Element, open: boolean): void {
    const button = childOf(entry, BUTTON);
    if (!(button instanceof HTMLElement)) {
        return;
    }

    stayOpen(entry);

    // Before the list is hidden, or the focus would be on nothing drawn.
    if (!open && childOf(entry, LIST)?.contains(document.activeElement) === true) {
        button.focus();
    }

    button.setAttribute('aria-expanded', String(open));

    if (open && isBlock(entry)) {
        for (const other of openBlocks()) {
            if (other !== entry) {
                setOpen(other, false);
            }
        }
    }
}

/** Cancels a close the mouse leaving had scheduled. */
function stayOpen(entry: Element): void {
    const pending = leaving.get(entry);
    if (pending !== undefined) {
        window.clearTimeout(pending);
        leaving.delete(entry);
    }
}

function isOpen(entry: Element): boolean {
    return childOf(entry, BUTTON)?.getAttribute('aria-expanded') === 'true';
}

/** Whether the theme opens this entry's list as a block over the page rather than in place. */
function isBlock(entry: Element): boolean {
    const list = childOf(entry, LIST);
    if (list === null) {
        return false;
    }

    const position = getComputedStyle(list).position;

    return position === 'absolute' || position === 'fixed';
}

/** The entry whose block $target is in or on, if the theme opens one there. */
function blockEntryOf(target: EventTarget | null): Element | null {
    let entry = target instanceof Element ? target.closest(ENTRY) : null;
    while (entry !== null) {
        if (isBlock(entry)) {
            return entry;
        }

        entry = entry.parentElement?.closest(ENTRY) ?? null;
    }

    return null;
}

function openBlocks(): Element[] {
    const open: Element[] = [];
    for (const button of document.querySelectorAll(`${BUTTON}[aria-expanded="true"]`)) {
        const entry = button.parentElement;
        if (entry !== null && isBlock(entry)) {
            open.push(entry);
        }
    }

    return open;
}

function childOf(entry: Element, selector: string): Element | null {
    for (const child of entry.children) {
        if (child.matches(selector)) {
            return child;
        }
    }

    return null;
}
