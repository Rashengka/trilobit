/**
 * The style guide's own bundle, loaded after the shared one and only by the
 * pages of the guide - see src/Core/Presentation/Styleguide/templates/@layout.latte.
 *
 * Two things live here and nowhere else: the highlighter that colours the code
 * the guide shows, and the button that copies it.
 *
 * **Nothing here imports Naja, and that is on purpose.** A module both this
 * bundle and app.js import would be split by the build into a chunk the two
 * share, and a chunk is reached by an import inside a bundle rather than by a
 * tag the server writes - so it would carry no ?v= and a browser could keep an
 * old copy of it (tests/frontend/build-output.test.mjs refuses the build that
 * does it). What Naja's afterUpdate would have been for is done by watching
 * the document instead: whatever arrives in it, by a Naja redraw or by
 * anything else, is prepared like the page was. Preparing something twice
 * changes nothing, so there is nothing to keep track of and nothing to clean
 * up before a redraw.
 */
import { copyWhenAsked, offerCopyingWithin } from './styleguide/copy';
import { highlightWithin } from './styleguide/highlight';

function prepare(root: Element | Document): void {
    highlightWithin(root);
    offerCopyingWithin(root);
}

prepare(document);
copyWhenAsked();

new MutationObserver((records: MutationRecord[]): void => {
    for (const record of records) {
        for (const node of record.addedNodes) {
            if (node instanceof Element) {
                prepare(node);
            }
        }
    }
}).observe(document.body, { childList: true, subtree: true });
