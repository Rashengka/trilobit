/**
 * A c-toast is announced when it arrives.
 *
 * A toast's sentence is a live region, and a live region is read when its
 * content changes after the screen reader has seen it - not when it arrives
 * already holding its sentence. Every toast does arrive that way: on the page a
 * redirect led to, drawn by the server, or in the snippet a Naja answer put in.
 * So once a toast is on the page, its sentence is put in again, the same words
 * in new nodes, which is a change of the region and nothing a reader sees.
 *
 * **The wait before it** is the time the page needs to have the region in its
 * accessibility tree first; a change made in the same moment as the arrival is
 * a change to something nobody was listening to yet. A tenth of a second is
 * well under what anybody would notice as late, and it is what libraries that
 * announce this way wait too.
 *
 * **Nothing is set up per toast and nothing is cleaned up.** What arrives is
 * noticed by watching the document, the way assets/styleguide.ts notices the
 * code it colours, so a toast Naja brings in is announced without Naja being
 * asked, and there is no mark on it: a mark would be kept in Naja's history
 * with the markup, and would come back over a toast that was never announced.
 * A toast brought back by the history is on the page again and is announced
 * again, which is what it would be if it were drawn again.
 */

const MESSAGE = '.c-toast__message';

/** Milliseconds between a toast arriving and its sentence being put in again. */
const SETTLE = 100;

export function announceTheToasts(): void {
    announceWithin(document);

    new MutationObserver((records: MutationRecord[]): void => {
        for (const record of records) {
            for (const node of record.addedNodes) {
                // Text is ignored, so putting a sentence in again below is not
                // itself a toast arriving.
                if (node instanceof Element) {
                    announceWithin(node);
                }
            }
        }
    }).observe(document.body, { childList: true, subtree: true });
}

function announceWithin(root: Element | Document): void {
    const messages = [
        ...(root instanceof Element && root.matches(MESSAGE) ? [root] : []),
        ...root.querySelectorAll(MESSAGE),
    ];
    if (messages.length === 0) {
        return;
    }

    window.setTimeout(() => {
        for (const message of messages) {
            if (message.isConnected) {
                message.replaceChildren(...[...message.childNodes].map((node) => node.cloneNode(true)));
            }
        }
    }, SETTLE);
}
