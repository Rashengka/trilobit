/**
 * Hanging the popover of c-dropdown, c-popover or c-tooltip from the element it
 * belongs to, in a browser that cannot do it from the stylesheet.
 *
 * Where CSS anchor positioning is there - Chrome and Edge from 125, Safari from
 * 26.0, Firefox from 147 - assets/base.css places all three with position-area,
 * turns them to the other side with position-try-fallbacks when there is no
 * room, and keeps them there while the page scrolls. This file then does
 * nothing at all.
 *
 * Anywhere else the browser still opens and closes them and draws them in the
 * top layer, but would put them in the middle of the window. So on opening,
 * and again whenever the page scrolls or the window changes size while one is
 * open, it is put where the stylesheet would have put it: against the edge of
 * its holder (the parent the stylesheet anchors to), on the side data-side asks
 * for unless there is less room there than on the other, lined up as
 * data-align asks - start, end, or the middle - and never past the edge of the
 * window. The gap is the popover's own margin, which the stylesheet gives it
 * from a token in both cases.
 *
 * It is a fallback and knowingly a smaller one: no flipping along the inline
 * axis, and left to right only.
 *
 * Nothing is prepared per popover: the opening is heard on the document, so
 * one Naja draws later is placed like the rest. What it leaves on the page is
 * the popover's own inline position, which is worked out again every time it
 * opens, so a copy of the page coming back from Naja's history carries nothing
 * that could be out of date.
 */

const FLOATING = '.c-dropdown__menu, .c-popover__panel, .c-tooltip__tip';

export function placeWhereTheBrowserCannotAnchor(): void {
    if (CSS.supports('position-area', 'block-end') || !('popover' in HTMLElement.prototype)) {
        return;
    }

    document.addEventListener(
        'toggle',
        (event: Event): void => {
            const floating = event.target;
            if (floating instanceof HTMLElement && floating.matches(FLOATING) && (event as ToggleEvent).newState === 'open') {
                place(floating);
            }
        },
        // toggle does not bubble; it is heard on its way down instead.
        { capture: true },
    );

    const again = (): void => {
        for (const floating of document.querySelectorAll<HTMLElement>(`:is(${FLOATING}):popover-open`)) {
            place(floating);
        }
    };

    window.addEventListener('scroll', again, { capture: true, passive: true });
    window.addEventListener('resize', again, { passive: true });
}

function place(floating: HTMLElement): void {
    const holder = floating.parentElement;
    if (holder === null) {
        return;
    }

    const style = floating.style;
    style.inset = 'auto';

    const edge = holder.getBoundingClientRect();
    const size = floating.getBoundingClientRect();
    const gap = Number.parseFloat(getComputedStyle(floating).marginBlockStart) || 0;
    const width = document.documentElement.clientWidth;
    const height = document.documentElement.clientHeight;

    const roomBelow = height - edge.bottom;
    const roomAbove = edge.top;
    const needed = size.height + gap;
    const wanted = floating.dataset.side === 'above' ? 'above' : 'below';
    const side =
        wanted === 'below'
            ? roomBelow < needed && roomAbove > roomBelow
                ? 'above'
                : 'below'
            : roomAbove < needed && roomBelow > roomAbove
              ? 'below'
              : 'above';

    // The margin on the side facing the holder is the gap, as it is when the
    // stylesheet places it.
    if (side === 'below') {
        style.top = `${edge.bottom}px`;
    } else {
        style.bottom = `${height - edge.top}px`;
    }

    const align = floating.dataset.align;
    const left =
        align === 'start'
            ? edge.left
            : align === 'end'
              ? edge.right - size.width
              : edge.left + (edge.width - size.width) / 2;

    style.left = `${Math.min(Math.max(0, left), Math.max(0, width - size.width))}px`;
}
