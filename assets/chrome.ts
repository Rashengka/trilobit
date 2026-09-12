import { observedAgainst, scroller } from './scroller';

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
 *
 * One more number is written beside it, for the same reason: how far down the
 * held banner reaches (--layout-banner-reach). A theme that holds its
 * navigation under the banner - atrium - holds it that far down, and the
 * banner's height is no more a theme's to write down than the offset is.
 */

/** The bands a theme may hold, as assets/base.css names them. */
const HELD = '.l-shell__banner, .l-shell__nav, .l-shell__nav-hold';

/** The banner of the page, which is the first one: a style guide specimen draws others further down. */
const BANNER = '.l-shell__banner';

/** The column whatever is held would be covering. */
const CONTENT = '.l-shell__main';

export function keepJumpsClearOfTheChrome(): void {
    const content = document.querySelector(CONTENT);
    if (content === null) {
        return;
    }

    // The reach first: the navigation's offset is read from it, and the
    // offset below is measured off the navigation.
    const update = (): void => {
        scroller().style.setProperty('--layout-banner-reach', `${reach(document.querySelector(BANNER))}px`);
        scroller().style.setProperty('--layout-chrome-offset', `${covered(content)}px`);
    };

    // Any band changing size is a banner that wrapped, a theme that changed, a
    // window that crossed a breakpoint, or a band a theme makes smaller once
    // the page has scrolled; the content changing size is the column moving
    // under the bands.
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

/**
 * Marks the page's own banner and navigation with data-scrolled="true" while
 * the content has scrolled up under them, and with "false" once it is back.
 * What a band does about it is its theme's: atrium makes both smaller, ledger
 * does nothing (.ai/plans/09-chrome-a-sirka-obsahu.md, L3).
 *
 * **Watched, never listened for.** Nothing here runs on a scroll event. An
 * IntersectionObserver is told about an invisible sentinel, and it is told only
 * when the sentinel crosses the edge of the window - once each way, however far
 * and however fast the page scrolls.
 *
 * **The sentinel is where the mark cannot undo itself.** The trap of a band
 * that changes its height while the page scrolls: it gets smaller, the content
 * under it moves up by as much, and either the content or the scroll position
 * moves back across whatever decided to make it smaller - so it grows again, and
 * blinks. The sentinel (assets/base.css) spans the rows above the content: it
 * starts at the top of the page, over the banner, and ends where the content
 * begins. Its lower edge is the threshold, and that edge moves with the content
 * when the bands change size, by exactly as much. A browser that keeps the
 * reader's place moves the scroll position by the same amount in the same
 * direction, so the edge stays where it was in the window; one that does not
 * leaves the scroll position alone, and the edge moves further past the top of
 * the window in whichever direction the bands just went. Either way the change
 * carries the sentinel away from the threshold and never back across it. The
 * bands get smaller once as much has been scrolled as they are tall at full
 * size, and grow back once no more is scrolled than they are tall when small;
 * the difference between the two is exactly how much they changed, and no
 * number is chosen for it.
 *
 * The mark goes on the bands and not on the root, so that what a theme
 * redeclares under it reaches the chrome of the page and nothing else - a
 * specimen of the navigation further down the style guide stays the size it is.
 */
export function markTheChromeScrolledPast(): void {
    const shell = document.querySelector('.l-shell');
    if (shell === null || shell.querySelector(`:scope > ${CONTENT}`) === null) {
        return;
    }

    const sentinel = document.createElement('div');
    sentinel.setAttribute('data-chrome-sentinel', '');
    sentinel.setAttribute('aria-hidden', 'true');
    shell.prepend(sentinel);

    const bands = shell.querySelectorAll(':scope > .l-shell__banner, :scope > .l-shell__nav');

    new IntersectionObserver(
        (entries) => {
            const latest = entries[entries.length - 1];
            if (latest === undefined) {
                return;
            }

            // A value in both states rather than a mark that comes and goes:
            // a band without the attribute is one this has not reported on
            // yet, and assets/base.css animates only the bands it has.
            for (const band of bands) {
                band.setAttribute('data-scrolled', String(!latest.isIntersecting));
            }
        },
        { root: observedAgainst() },
    ).observe(sentinel);
}

/** How far down the window $band reaches while it is held, and nothing when it is not. */
function reach(band: Element | null): number {
    if (band === null) {
        return 0;
    }

    const style = getComputedStyle(band);
    if (style.position !== 'sticky') {
        return 0;
    }

    const offset = Number.parseFloat(style.insetBlockStart);

    return (Number.isNaN(offset) ? 0 : offset) + band.getBoundingClientRect().height;
}

function covered(content: Element): number {
    const column = content.getBoundingClientRect();

    let lowest = 0;
    for (const band of document.querySelectorAll(HELD)) {
        const box = band.getBoundingClientRect();
        if (box.right <= column.left || box.left >= column.right) {
            continue;
        }

        lowest = Math.max(lowest, reach(band));
    }

    return lowest;
}
