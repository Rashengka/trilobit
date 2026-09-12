/**
 * What scrolls the page, answered in one place.
 *
 * The document scrolls, and not a container of the page's own. That was
 * decided for what a browser does for the document and for nothing else: the
 * jump to an anchor, the position given back on the way back through history,
 * find in page, and the address bar that hides itself on a phone.
 *
 * The decision can be taken back, and what taking it back costs depends on how
 * many places ask the question. So everything that needs the element that
 * scrolls asks this function, and nothing reaches for window.scroll* or for the
 * root element on its own account. Changing the answer is then this function,
 * and not a search for every place that assumed. The stylesheet has nothing to
 * move with it: the clearance a jump to an anchor keeps under the held banner
 * is a margin on what is jumped to (assets/base.css), which works for whatever
 * scrolls.
 */
export function scroller(): HTMLElement {
    const element = document.scrollingElement;

    return element instanceof HTMLElement ? element : document.documentElement;
}

/**
 * What an IntersectionObserver watching against the thing that scrolls is
 * given as its root - the same answer, in the form an observer takes it.
 *
 * The document is watched against the window, and null is how an observer
 * names the window. Handing it the root element instead would look the same and
 * be wrong in silence: the root element is as tall as the page, so everything
 * on the page would intersect it wherever the page was scrolled.
 */
export function observedAgainst(): Element | null {
    const element = scroller();

    return element === document.documentElement || element === document.scrollingElement ? null : element;
}
