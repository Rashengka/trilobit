/**
 * Prism, its core and the languages the style guide shows, named one by one.
 *
 * Not the package's main file, which is the core with four languages chosen by
 * somebody else, and not every language, which is some three hundred: each
 * language is a file of its own, and a language imported is a language
 * shipped. They are imported in the order they build on each other - Latte is
 * written as markup with PHP inside it, so it needs markup-templating and PHP
 * before it, and PHP needs markup-templating in turn.
 *
 * No theme of Prism's is imported either. The colours of its tokens are the
 * design system's, read out of tokens in assets/base.css.
 *
 * Prism highlights the whole page by itself as soon as it loads, unless it is
 * told beforehand that it is being driven by hand. ./prism-manual tells it,
 * and is imported first for that reason; highlight.ts then decides when and
 * what.
 */
import './prism-manual';
import 'prismjs/components/prism-core';
import 'prismjs/components/prism-markup';
import 'prismjs/components/prism-css';
import 'prismjs/components/prism-clike';
import 'prismjs/components/prism-javascript';
import 'prismjs/components/prism-typescript';
import 'prismjs/components/prism-markup-templating';
import 'prismjs/components/prism-php';
import 'prismjs/components/prism-latte';
import 'prismjs/components/prism-json';
import 'prismjs/components/prism-bash';
import 'prismjs/components/prism-sql';
// NEON is left out while bin/check-leaks reads a regular expression of its
// grammar - a time of day, two digits, a colon and two digits - in the built
// file as a Windows path, the letter of a digit class standing for the drive.
// Whether that file is exempted from the rule or the rule narrowed is the
// owner's decision, not this file's; until one of the two is made, NEON is not
// highlighted.

/** The part of Prism this bundle uses. The package ships no types, and adding a package for them is not worth it. */
export interface Prism {
    manual?: boolean;
    highlightElement(element: Element): void;
}

/** Prism puts itself on window, and the language files find it there. */
export function prism(): Prism {
    return (window as unknown as { Prism: Prism }).Prism;
}
