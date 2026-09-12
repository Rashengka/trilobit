<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Styleguide;

use Dom\Comment;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\HTMLElement;
use Dom\Node;
use Dom\NodeList;
use Dom\Text;

/**
 * The HTML of a specimen laid out the way somebody would write it by hand, to
 * be read and copied under the specimen - whatever shape the templates that
 * printed it left it in.
 *
 * Templates leave HTML the shape of the templates: a tag written over several
 * lines ends with its bracket on a line of its own, a block printed by another
 * block starts straight after its parent's tag, and a closing tag lands at the
 * left edge. Taking the shared indentation off, which is all SourceText does,
 * cannot straighten that. So the HTML is read as a browser reads it and
 * written out again:
 *
 * - every block - an element the browser's own stylesheet draws as a block, a
 *   list item or a part of a table - on a line of its own, indented by four
 *   spaces for every block it is inside;
 * - words and the inline elements among them on the line they are in, their
 *   runs of whitespace one space;
 * - elements side by side with no words between them on lines of their own,
 *   where they were on lines of their own when they were printed;
 * - a drawing's shapes on lines of their own, unless the drawing sits in a line
 *   of text, where it stays whole;
 * - what pre, textarea, script and style hold exactly as it is;
 * - every attribute in its order with its value, escaped as HTML is; an
 *   attribute that is on by being there written without a value when it has
 *   none, and an element that holds nothing written without a closing tag.
 *
 * **It changes nothing a browser draws.** A line is broken only where
 * whitespace was already drawn as nothing - next to a block, at either end of
 * one, between the shapes of a drawing - or where there was whitespace anyway,
 * because a break is drawn as one space and so is any whitespace. So no word
 * moves: the word printed straight after a tag stays straight after it.
 * Trilobit\Tests\Template\StyleguideFormatsTheHtmlOfEverySpecimenTest holds
 * that over every specimen of the style guide.
 */
final class HtmlSource
{
    private const string INDENT = '    ';

    private const string WHITESPACE = " \t\n\f\r";

    private const string HTML = 'http://www.w3.org/1999/xhtml';

    private const string SVG = 'http://www.w3.org/2000/svg';

    /** What the browser's stylesheet draws as a block, a list item or a part of a table. */
    private const array BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'caption', 'col', 'colgroup', 'dd', 'details', 'dialog', 'div',
        'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header',
        'hgroup', 'hr', 'legend', 'li', 'main', 'menu', 'nav', 'ol', 'optgroup', 'option', 'p', 'pre', 'search',
        'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    /** Elements that hold nothing and so have no closing tag. */
    private const array VOID = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr',
    ];

    /** Elements whose text is code, not HTML: written as it is, never escaped. */
    private const array RAW = ['script', 'style', 'xmp', 'iframe', 'noembed', 'noframes'];

    /** Elements whose whitespace is content. */
    private const array PREFORMATTED = ['pre', 'textarea', 'listing'];

    /** Attributes that are on by being there; written without a value when they have none. */
    private const array BOOLEAN = [
        'allowfullscreen', 'async', 'autofocus', 'autoplay', 'checked', 'controls', 'default', 'defer', 'disabled',
        'formnovalidate', 'hidden', 'inert', 'ismap', 'itemscope', 'loop', 'multiple', 'muted', 'nomodule',
        'novalidate', 'open', 'playsinline', 'popover', 'readonly', 'required', 'reversed', 'selected',
    ];

    /** The elements of a drawing whose text is drawn, so whitespace in them is text like any other. */
    private const array DRAWN_TEXT = ['text', 'tspan', 'textPath', 'title', 'desc', 'style', 'script', 'foreignObject'];

    /** A piece of HTML, read where a specimen is drawn - inside the body - and laid out. */
    public static function format(string $html): string
    {
        $body = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NOERROR,
            'UTF-8',
        )->body;

        if (!$body instanceof HTMLElement) {
            return '';
        }

        $flow = self::flow($body->childNodes, true, false);

        return implode("\n", array_map(
            static fn(array $line): string => $line[1] === '' ? '' : str_repeat(self::INDENT, $line[0]) . $line[1],
            $flow['lines'],
        ));
    }

    /**
     * One element laid out: its lines, each with the depth it sits at below
     * the element's own first line. Flat keeps the whole element on one line;
     * verbatim keeps every character of what it holds.
     *
     * @return list<array{int, string}>
     */
    private static function element(Element $element, bool $flat, bool $verbatim): array
    {
        $name = $element->localName;
        $open = '<' . $name . self::attributes($element);
        $close = '</' . $name . '>';

        if ($element->namespaceURI === self::HTML) {
            if (in_array($name, self::VOID, true)) {
                return [[0, $open . '>']];
            }

            if ($name === 'template') {
                // What a template holds is not among its children, and no
                // specimen has one; the browser's own writing of it will do.
                return [[0, $open . '>' . $element->innerHTML . $close]];
            }

            if (in_array($name, self::RAW, true)) {
                return [[0, $open . '>' . $element->textContent . $close]];
            }

            if (in_array($name, self::PREFORMATTED, true)) {
                $inner = self::inline($element->childNodes, true, false);

                // A parser drops a line break straight after the tag, so one
                // that is content has to be written after a second one.
                return [[0, $open . '>' . (str_starts_with($inner, "\n") ? "\n" : '') . $inner . $close]];
            }
        } elseif (!$element->hasChildNodes()) {
            return [[0, $open . ' />']];
        }

        $drawing = self::isDrawing($element);
        if ($flat || $verbatim) {
            return [[0, $open . '>' . self::inline($element->childNodes, $verbatim, $drawing) . $close]];
        }

        $flow = self::flow($element->childNodes, $drawing || self::isBlock($element), $drawing);
        [$lines, $multiline, $lead, $trail] = [$flow['lines'], $flow['multiline'], $flow['lead'], $flow['trail']];

        if (!$multiline) {
            return [[0, $open . '>' . ($lead ? ' ' : '') . $lines[0][1] . ($trail ? ' ' : '') . $close]];
        }

        $laidOut = [[0, $open . '>']];
        foreach ($lines as $index => [$depth, $text]) {
            if ($index === 0 && !$lead) {
                $laidOut = self::extend($laidOut, $text);
            } else {
                $laidOut[] = [$depth + 1, $text];
            }
        }

        return $trail ? [...$laidOut, [0, $close]] : self::extend($laidOut, $close);
    }

    /**
     * What an element holds, laid out.
     *
     * The children are cut into items - a run of words, an element, a comment -
     * and between every two of them, and at either end, is a gap that either
     * had whitespace in it or not. A gap breaks the line where it may and where
     * it helps: it may where whitespace was drawn as nothing (see the class) or
     * where there was whitespace anyway; it helps next to a block or next to an
     * element that takes more than a line, and between two elements that were
     * printed on lines of their own. A gap that does not break is one space if
     * it had whitespace, and nothing if it had none.
     *
     * Lead and trail say what happens at the two ends: on several lines, whether
     * the content starts and ends on lines of its own rather than on the lines
     * of the tags; on one, whether a space is kept after the opening tag and
     * before the closing one.
     *
     * @param NodeList<Node> $nodes
     * @param bool $free whether whitespace at either end is drawn as nothing - inside a block or a drawing
     * @param bool $drawing whether whitespace between any two children is drawn as nothing
     *
     * @return array{lines: non-empty-list<array{int, string}>, multiline: bool, lead: bool, trail: bool}
     */
    private static function flow(NodeList $nodes, bool $free, bool $drawing): array
    {
        /** @var list<array{kind: 'text'|'element'|'comment', text: string, node: ?Element}> $items */
        $items = [];
        /** @var non-empty-list<array{space: bool, newline: bool}> $gaps */
        $gaps = [['space' => false, 'newline' => false]];

        foreach ($nodes as $node) {
            if ($node instanceof Text) {
                $data = $node->data;
                $core = trim($data, self::WHITESPACE);
                $leading = substr($data, 0, strspn($data, self::WHITESPACE));
                self::widen($gaps, $leading);
                if ($core === '') {
                    continue;
                }

                $items[] = ['kind' => 'text', 'text' => self::escape(self::collapse($core)), 'node' => null];
                $gaps[] = ['space' => false, 'newline' => false];
                self::widen($gaps, substr($data, strlen(rtrim($data, self::WHITESPACE))));
            } elseif ($node instanceof Element) {
                $items[] = ['kind' => 'element', 'text' => '', 'node' => $node];
                $gaps[] = ['space' => false, 'newline' => false];
            } elseif ($node instanceof Comment) {
                $items[] = ['kind' => 'comment', 'text' => '<!--' . $node->data . '-->', 'node' => null];
                $gaps[] = ['space' => false, 'newline' => false];
            }
        }

        $count = count($items);
        if ($count === 0) {
            return ['lines' => [[0, '']], 'multiline' => false, 'lead' => !$free && $gaps[0]['space'], 'trail' => false];
        }

        /** @var list<array{block: bool, big: bool, element: bool, lines: non-empty-list<array{int, string}>}> $laid */
        $laid = [];
        foreach ($items as $index => $item) {
            $element = $item['node'];
            if ($element === null) {
                $laid[] = ['block' => false, 'big' => false, 'element' => $item['kind'] === 'comment', 'lines' => [[0, $item['text']]]];

                continue;
            }

            $lines = self::element($element, self::sitsInText($items, $gaps, $index) && !self::holdsBlock($element), false);
            $laid[] = ['block' => self::isBlock($element), 'big' => count($lines) > 1, 'element' => true, 'lines' => $lines];
        }

        $breaks = [];
        $multiline = $drawing;
        foreach ($laid as $item) {
            $multiline = $multiline || $item['block'] || $item['big'];
        }

        for ($index = 1; $index < $count; $index++) {
            [$before, $after, $gap] = [$laid[$index - 1], $laid[$index], $gaps[$index]];
            $block = $before['block'] || $after['block'];
            $breaks[$index] = ($drawing || $block || $gap['space'])
                && ($drawing || $block || $before['big'] || $after['big'] || ($before['element'] && $after['element'] && $gap['newline']));
            $multiline = $multiline || $breaks[$index];
        }

        $startFree = $free || $laid[0]['block'];
        $endFree = $free || $laid[$count - 1]['block'];
        $lead = $multiline ? ($startFree || $gaps[0]['space']) : (!$startFree && $gaps[0]['space']);
        $trail = $multiline ? ($endFree || $gaps[$count]['space']) : (!$endFree && $gaps[$count]['space']);

        $lines = [[0, '']];
        foreach ($laid as $index => $item) {
            if ($index > 0) {
                if ($breaks[$index]) {
                    $lines[] = [0, ''];
                } elseif ($gaps[$index]['space']) {
                    $lines = self::extend($lines, ' ');
                }
            }

            $base = $lines[array_key_last($lines)][0];
            foreach ($item['lines'] as $line => [$depth, $text]) {
                if ($line === 0) {
                    $lines = self::extend($lines, $text);
                } else {
                    $lines[] = [$base + $depth, $text];
                }
            }
        }

        return ['lines' => $lines, 'multiline' => $multiline, 'lead' => $lead, 'trail' => $trail];
    }

    /**
     * Whether the element at this index sits in a line of text: a run of words
     * next to it on either side, with no line break between the two.
     *
     * @param list<array{kind: 'text'|'element'|'comment', text: string, node: ?Element}> $items
     * @param non-empty-list<array{space: bool, newline: bool}> $gaps
     */
    private static function sitsInText(array $items, array $gaps, int $index): bool
    {
        return (($items[$index - 1]['kind'] ?? null) === 'text' && !$gaps[$index]['newline'])
            || (($items[$index + 1]['kind'] ?? null) === 'text' && !($gaps[$index + 1]['newline'] ?? false));
    }

    /**
     * Records whitespace in the gap being written.
     *
     * @param non-empty-list<array{space: bool, newline: bool}> $gaps
     */
    private static function widen(array &$gaps, string $whitespace): void
    {
        if ($whitespace === '') {
            return;
        }

        $last = array_key_last($gaps);
        $gaps[$last]['space'] = true;
        $gaps[$last]['newline'] = $gaps[$last]['newline'] || str_contains($whitespace, "\n");
    }

    /**
     * The lines with text written on at the end of the last one.
     *
     * @param non-empty-list<array{int, string}> $lines
     *
     * @return non-empty-list<array{int, string}>
     */
    private static function extend(array $lines, string $text): array
    {
        $last = array_pop($lines);
        $lines[] = [$last[0], $last[1] . $text];

        return $lines;
    }

    /**
     * Children written on one line: runs of whitespace as one space, or every
     * character as it is when verbatim, and nothing between shapes of a drawing.
     *
     * @param NodeList<Node> $nodes
     */
    private static function inline(NodeList $nodes, bool $verbatim, bool $drawing): string
    {
        $written = '';
        foreach ($nodes as $node) {
            if ($node instanceof Text) {
                if ($drawing && !$verbatim && trim($node->data, self::WHITESPACE) === '') {
                    continue;
                }

                $written .= self::escape($verbatim ? $node->data : self::collapse($node->data));
            } elseif ($node instanceof Element) {
                $written .= self::element($node, true, $verbatim)[0][1];
            } elseif ($node instanceof Comment) {
                $written .= '<!--' . $node->data . '-->';
            }
        }

        return $written;
    }

    private static function attributes(Element $element): string
    {
        $html = $element->namespaceURI === self::HTML;

        $written = '';
        foreach ($element->attributes as $attribute) {
            $written .= ' ' . $attribute->name;
            if ($attribute->value !== '' || !$html || !in_array($attribute->name, self::BOOLEAN, true)) {
                $written .= '="' . strtr($attribute->value, ['&' => '&amp;', '"' => '&quot;', "\u{A0}" => '&nbsp;']) . '"';
            }
        }

        return $written;
    }

    private static function escape(string $text): string
    {
        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', "\u{A0}" => '&nbsp;']);
    }

    private static function collapse(string $text): string
    {
        return (string) preg_replace('/[ \t\n\f\r]+/', ' ', $text);
    }

    private static function isBlock(Element $element): bool
    {
        return $element->namespaceURI === self::HTML && in_array($element->localName, self::BLOCKS, true);
    }

    /** A drawing, or a group in one: whitespace between its children is drawn as nothing. */
    private static function isDrawing(Element $element): bool
    {
        return $element->namespaceURI === self::SVG && !in_array($element->localName, self::DRAWN_TEXT, true);
    }

    /** Whether a block is anywhere inside: such an element cannot be kept on one line. */
    private static function holdsBlock(Element $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof Element && (self::isBlock($child) || self::holdsBlock($child))) {
                return true;
            }
        }

        return false;
    }
}
