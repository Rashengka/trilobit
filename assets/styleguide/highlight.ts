import { prism } from './prism';

/**
 * Code the highlighter colours: a <code> whose class names its language, in a
 * block or inline. The class is how the language is chosen, and an element
 * without one is left as it is.
 */
const CODE = 'code[class*="language-"]';

/**
 * Colours every piece of code at or under `root`.
 *
 * It may be asked about code it has already coloured, and that changes
 * nothing: Prism reads the text of the element again rather than its markup,
 * so a second pass produces the same tokens as the first instead of wrapping
 * them. That is what lets the style guide hand it whatever arrives in the page
 * without remembering what it has seen - tests/e2e/styleguide-code.spec.ts
 * holds it to that.
 */
export function highlightWithin(root: Element | Document): void {
    if (root instanceof Element && root.matches(CODE)) {
        prism().highlightElement(root);
    }

    for (const code of root.querySelectorAll(CODE)) {
        prism().highlightElement(code);
    }
}
