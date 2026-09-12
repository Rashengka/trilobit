/**
 * Showing and hiding the tip of c-tooltip, as WCAG 1.4.13 asks of content that
 * appears on hover or on focus.
 *
 *   - It appears when the mouse rests on the element and when the focus arrives
 *     at it.
 *   - Hoverable: it stays while the pointer moves from the element onto the tip
 *     itself. The tip is drawn a small gap away, so leaving the element closes
 *     it only after a moment's grace, which the pointer crossing the gap uses.
 *   - Persistent: it stays for as long as the pointer is on either or the focus
 *     is on the element, and goes when neither is.
 *   - Dismissible: Escape puts it away without the pointer or the focus moving,
 *     and it is not brought back until it is asked for again - the pointer
 *     leaving and coming back, or the focus leaving and coming back.
 *
 * Only the mouse hovers, as in assets/nav.ts: on a touch screen "resting on it"
 * is a tap that also presses the element, and a tap that focuses the element
 * shows the tip by the focus anyway.
 *
 * The tip is a manual popover: shown by showPopover() in the top layer, closed
 * by nothing but this file, and closing nothing itself.
 *
 * Everything is heard on the document and nothing is marked on the page, so a
 * tooltip Naja draws later works without being set up; the grace timers and the
 * tooltips put away by Escape are kept in here, keyed by the element, and go
 * with it.
 */

const HOLDER = '.c-tooltip';
const TIP = '.c-tooltip__tip';

/** How long the pointer may be off both the element and the tip before the tip goes. */
const GRACE_MS = 200;

const leaving = new Map<Element, number>();

const dismissed = new WeakSet<Element>();

export function showTheTooltips(): void {
    if (!('popover' in HTMLElement.prototype)) {
        return;
    }

    document.addEventListener('pointerover', (event: PointerEvent): void => {
        const holder = holderOf(event.target);
        if (holder === null || event.pointerType !== 'mouse') {
            return;
        }

        stay(holder);
        if (!dismissed.has(holder)) {
            show(holder);
        }
    });

    document.addEventListener('pointerout', (event: PointerEvent): void => {
        const holder = holderOf(event.target);
        if (holder === null || event.pointerType !== 'mouse') {
            return;
        }

        if (event.relatedTarget instanceof Node && holder.contains(event.relatedTarget)) {
            return;
        }

        stay(holder);
        leaving.set(
            holder,
            window.setTimeout(() => {
                leaving.delete(holder);
                dismissed.delete(holder);
                if (!holder.contains(document.activeElement)) {
                    hide(holder);
                }
            }, GRACE_MS),
        );
    });

    document.addEventListener('focusin', (event: FocusEvent): void => {
        const holder = holderOf(event.target);
        if (holder !== null) {
            dismissed.delete(holder);
            show(holder);
        }
    });

    document.addEventListener('focusout', (event: FocusEvent): void => {
        const holder = holderOf(event.target);
        if (holder === null || (event.relatedTarget instanceof Node && holder.contains(event.relatedTarget))) {
            return;
        }

        dismissed.delete(holder);
        if (!holder.matches(':hover')) {
            hide(holder);
        }
    });

    document.addEventListener('keydown', (event: KeyboardEvent): void => {
        if (event.key !== 'Escape') {
            return;
        }

        for (const tip of document.querySelectorAll(`${TIP}:popover-open`)) {
            const holder = tip.parentElement;
            if (holder !== null) {
                dismissed.add(holder);
                hide(holder);
            }
        }
    });
}

function show(holder: Element): void {
    for (const open of document.querySelectorAll(`${TIP}:popover-open`)) {
        if (open.parentElement !== holder && open.parentElement !== null) {
            hide(open.parentElement);
        }
    }

    const tip = tipOf(holder);
    if (tip !== null && !tip.matches(':popover-open')) {
        tip.showPopover();
    }
}

function hide(holder: Element): void {
    stay(holder);
    const tip = tipOf(holder);
    if (tip !== null && tip.matches(':popover-open')) {
        tip.hidePopover();
    }
}

/** Cancels a hiding the pointer leaving had scheduled. */
function stay(holder: Element): void {
    const pending = leaving.get(holder);
    if (pending !== undefined) {
        window.clearTimeout(pending);
        leaving.delete(holder);
    }
}

function holderOf(target: EventTarget | null): Element | null {
    return target instanceof Element ? target.closest(HOLDER) : null;
}

function tipOf(holder: Element): HTMLElement | null {
    for (const child of holder.children) {
        if (child instanceof HTMLElement && child.matches(TIP)) {
            return child;
        }
    }

    return null;
}
