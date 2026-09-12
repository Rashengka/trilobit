import { scroller } from './scroller';

/**
 * How much of the top of the window the chrome covers while a theme holds it in
 * view, kept where a jump to an anchor and focus from the keyboard read it.
 *
 * assets/base.css gives everything in the content column a scroll margin of
 * --layout-chrome-offset, so a heading jumped to, or a link tabbed onto, stops
 * under the held banner rather than behind it. The number is written onto the
 * scroller, which every one of them sits inside and inherits it from. It is
 * measured rather than declared. The
 * banner is as tall as what is in it - a tagline that wraps, the menu of
 * whoever is signed in - and a height written into a theme beside the one the
 * browser lays out would be a second number, right until the day either of them
 * changes. Measured, it follows the banner through a switch of theme, through
 * the breakpoint at which a theme lets go, and through a banner that changes its
 * own height while the page scrolls.
 *
 * What counts is every band the page holds (its used position is sticky) that
 * covers the content column: a band above the content counts with its offset
 * and its height, and a band beside it - ledger's navigation - covers none of
 * it. Several bands held one under another cover as far down as the lowest of
 * them reaches, which is their heights added up.
 */

/** The bands a theme may hold, as assets/base.css names them. */
const HELD = '.l-shell__banner, .l-shell__nav-hold';

/** The column whatever is held would be covering. */
const CONTENT = '.l-shell__main';

export function keepJumpsClearOfTheChrome(): void {
    const content = document.querySelector(CONTENT);
    if (content === null) {
        return;
    }

    const update = (): void => {
        scroller().style.setProperty('--layout-chrome-offset', `${covered(content)}px`);
    };

    // Any band changing size is a banner that wrapped, a theme that changed, or
    // a window that crossed a breakpoint; the content changing size is the
    // column moving under the bands.
    const resized = new ResizeObserver(update);
    resized.observe(content);
    for (const band of document.querySelectorAll(HELD)) {
        resized.observe(band);
    }

    // A theme can hold a band or let it go without either of them changing
    // size, so a change of theme is watched for on its own.
    new MutationObserver(update).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme'],
    });

    update();
}

function covered(content: Element): number {
    const column = content.getBoundingClientRect();

    let reach = 0;
    for (const band of document.querySelectorAll(HELD)) {
        const style = getComputedStyle(band);
        if (style.position !== 'sticky') {
            continue;
        }

        const box = band.getBoundingClientRect();
        if (box.right <= column.left || box.left >= column.right) {
            continue;
        }

        const offset = Number.parseFloat(style.insetBlockStart);
        reach = Math.max(reach, (Number.isNaN(offset) ? 0 : offset) + box.height);
    }

    return reach;
}
