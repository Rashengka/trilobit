<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Comment;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\HTMLElement;
use Dom\Node;
use Dom\Text;

/**
 * HTML as the browser builds it, written out one node to a line, with only the
 * whitespace a browser draws nothing for taken out - so that two pieces of
 * HTML are the same page exactly when they come out the same here.
 *
 * What counts as nothing is decided here and on purpose not taken from the
 * formatter it is used to check (Trilobit\Core\Presentation\Styleguide\
 * HtmlSource): a list the two shared would let a mistake in it pass on both
 * sides at once. It is what the browser's own stylesheet says and nothing
 * more:
 *
 * - a run of whitespace in text is drawn as one space, so it counts as one;
 * - whitespace at the start or end of a block's content, and next to a block,
 *   is not drawn at all - a block is an element the browser's stylesheet
 *   draws as a block, a list item or a part of a table;
 * - whitespace between the shapes of a drawing is not drawn at all either;
 * - inside pre, textarea, script and style every character counts, and so it
 *   does in whatever those hold.
 *
 * Whitespace between two inline elements, or between one and a word, is drawn
 * as a space. It is kept, which is what makes this worth more than taking out
 * every run of whitespace between two tags: a formatter that glued two links
 * together would pass that, and fail this.
 */
final class MarkupTree
{
    /** What the browser's stylesheet draws as a block, a list item or a part of a table. */
    private const array BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'body', 'caption', 'col', 'colgroup', 'dd', 'details', 'dialog',
        'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'header', 'hgroup', 'hr', 'legend', 'li', 'main', 'menu', 'nav', 'ol', 'optgroup', 'option', 'p', 'pre',
        'search', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    /** Where every character of the text counts. */
    private const array VERBATIM = ['pre', 'textarea', 'listing', 'script', 'style', 'xmp', 'iframe', 'noembed', 'noframes'];

    /** The elements of a drawing whose text is drawn: whitespace in them is text like any other. */
    private const array DRAWN_TEXT = ['text', 'tspan', 'textPath', 'title', 'desc', 'style', 'script', 'foreignObject'];

    private const string HTML = 'http://www.w3.org/1999/xhtml';

    private const string SVG = 'http://www.w3.org/2000/svg';

    /** A piece of HTML, parsed where a specimen is drawn - inside the body - and written out a node to a line. */
    public static function of(string $html): string
    {
        $body = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NOERROR,
            'UTF-8',
        )->body;

        return $body instanceof HTMLElement ? implode("\n", self::children($body, 0, false)) : '';
    }

    /** @return list<string> */
    private static function children(Element $parent, int $depth, bool $verbatim): array
    {
        $nodes = [];
        foreach ($parent->childNodes as $node) {
            $nodes[] = $node;
        }

        $block = self::isBlock($parent);
        $drawing = $parent->namespaceURI === self::SVG && !in_array($parent->localName, self::DRAWN_TEXT, true);
        $indent = str_repeat('  ', $depth);

        $lines = [];
        foreach ($nodes as $index => $node) {
            if ($node instanceof Text) {
                $data = $node->data;
                if (!$verbatim) {
                    $data = (string) preg_replace('/[ \t\n\f\r]+/', ' ', $data);

                    $before = $nodes[$index - 1] ?? null;
                    if ($drawing || ($before instanceof Node ? self::isBlock($before) : $block)) {
                        $data = ltrim($data, ' ');
                    }

                    $after = $nodes[$index + 1] ?? null;
                    if ($drawing || ($after instanceof Node ? self::isBlock($after) : $block)) {
                        $data = rtrim($data, ' ');
                    }

                    if ($data === '') {
                        continue;
                    }
                }

                $lines[] = $indent . 'text ' . self::quoted($data);
            } elseif ($node instanceof Element) {
                $attributes = [];
                foreach ($node->attributes as $attribute) {
                    $attributes[] = $attribute->name . '=' . self::quoted($attribute->value);
                }

                $lines[] = $indent . ($node->namespaceURI === self::HTML ? '' : $node->namespaceURI . ' ')
                    . $node->localName . ($attributes === [] ? '' : ' ' . implode(' ', $attributes));

                array_push(
                    $lines,
                    ...self::children($node, $depth + 1, $verbatim || in_array($node->localName, self::VERBATIM, true)),
                );
            } elseif ($node instanceof Comment) {
                $lines[] = $indent . 'comment ' . self::quoted($node->data);
            }
        }

        return $lines;
    }

    private static function isBlock(?Node $node): bool
    {
        return $node instanceof Element
            && $node->namespaceURI === self::HTML
            && in_array($node->localName, self::BLOCKS, true);
    }

    private static function quoted(string $text): string
    {
        return json_encode($text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
