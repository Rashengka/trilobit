<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Styleguide;

/**
 * A piece of source cut out of where it was written, set flush to the left
 * edge the way somebody would write it on its own.
 *
 * The style guide shows every specimen twice more under itself - the HTML it
 * came out as and the Latte it was written in - and both arrive indented by
 * however deep they happened to sit: the Latte inside the page's blocks, the
 * HTML inside whatever the components printed around it. Neither indentation
 * says anything about the code, and a reader copying it would have to take it
 * out by hand.
 */
final class SourceText
{
    /**
     * Takes off the indentation every line shares, drops the empty lines at
     * either end and whitespace at the end of every line, and keeps no more
     * than one empty line in a row.
     *
     * A first line with no indentation at all is left out of the sharing: a
     * template printing it straight after something else took its indentation
     * away, and it would otherwise decide that no line has any to lose.
     */
    public static function dedent(string $source): string
    {
        $lines = [];
        foreach (explode("\n", $source) as $line) {
            $line = rtrim($line, " \t\r");
            if ($line === '' && ($lines === [] || end($lines) === '')) {
                continue;
            }

            $lines[] = $line;
        }

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        if ($lines === []) {
            return '';
        }

        $indents = [];
        foreach ($lines as $index => $line) {
            $indent = strspn($line, " \t");
            if ($line === '' || ($index === 0 && $indent === 0 && count($lines) > 1)) {
                continue;
            }

            $indents[] = $indent;
        }

        $shared = $indents === [] ? 0 : min($indents);

        return implode("\n", array_map(
            static fn(string $line): string => substr($line, min($shared, strspn($line, " \t"))),
            $lines,
        ));
    }
}
