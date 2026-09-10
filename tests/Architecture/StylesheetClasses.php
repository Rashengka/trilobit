<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Nette\Utils\FileSystem;
use Nette\Utils\Finder;

/**
 * What assets/base.css declares for each plain class name, and which class
 * names the templates put on one element together.
 *
 * It is a reader rather than a rule, so that the rule written on top of it -
 * Trilobit\Tests\Architecture\ClassesUsedTogetherDoNotCollideTest - can be run
 * over a stylesheet and a set of class lists that are made up on the spot. A
 * rule that only ever looks at the real files reports nothing and cannot be
 * shown to have looked.
 *
 * **Only plain single-class selectors are read**, so `.c-nav__link:hover`,
 * `.c-button[aria-pressed='true']` and `.c-prose > * + *` are all passed over.
 * That is not laziness: those weigh more than a bare class does, so which of
 * them applies is settled by the cascade and not by which line comes first,
 * which is exactly the difference the rule is about.
 *
 * **Only class lists written out in a template are read.** A list assembled at
 * run time - a class chosen by a PHP variable, a name built by concatenation -
 * is invisible here. **Exit condition:** the first component whose root class
 * is decided in PHP rather than written in the template.
 */
final class StylesheetClasses
{
    /**
     * Every plain class rule in $css, by the cascade layer it is written in
     * and by class name.
     *
     * The layer is part of the answer because it decides the question. Two
     * rules in different layers are ordered by the layers themselves, whatever
     * order the file puts them in, which is what layers are for; two rules in
     * the same layer with the same weight are ordered by the file alone, and
     * nothing in either of them says which was meant to win. Anything outside a
     * layer is left out - base.css puts one block there on purpose, and it
     * declares tokens rather than classes.
     *
     * @return array<string, array<string, list<string>>> layer => class => the
     *     properties that class declares, in the order they are written
     */
    public static function declaredIn(string $css): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $declared = [];
        foreach (self::layersIn($css) as $layer => $body) {
            $classes = [];
            foreach (self::rulesIn($body) as [$selector, $declarations]) {
                foreach (self::plainClassesIn($selector) as $class) {
                    $classes[$class] = [
                        ...($classes[$class] ?? []),
                        ...self::propertiesIn($declarations),
                    ];
                }
            }

            $declared[$layer] = $classes;
        }

        return $declared;
    }

    /**
     * Every list of class names written on one element in a template under
     * $directory.
     *
     * `n:class` is read as all of its string literals at once, which
     * over-reports on purpose: two of them may be alternatives a condition
     * chooses between, and reading them as though they could appear together
     * is the answer that is wrong in the safe direction.
     *
     * @return array<string, list<list<string>>> the file, relative to
     *     $directory, => the class lists written in it
     */
    public static function usedTogetherIn(string $directory): array
    {
        $used = [];
        foreach (Finder::findFiles('*.latte')->from($directory) as $file) {
            $path = (string) $file;
            $source = FileSystem::read($path);

            $lists = [];
            if (preg_match_all('/\bclass="([^"{$]*)"/', $source, $plain) === false) {
                continue;
            }

            foreach ($plain[1] as $value) {
                $written = preg_split('/\s+/', trim($value));
                $lists[] = self::namesIn($written === false ? [] : $written);
            }

            preg_match_all('/\bn:class="([^"]*)"/', $source, $conditional);
            foreach ($conditional[1] as $value) {
                preg_match_all("/'([^']*)'/", $value, $literals);
                $lists[] = self::namesIn($literals[1]);
            }

            $lists = array_values(array_filter($lists, static fn(array $list): bool => count($list) > 1));
            if ($lists !== []) {
                $used[substr($path, strlen($directory) + 1)] = $lists;
            }
        }

        ksort($used);

        return $used;
    }

    /**
     * Where two class names written on one element declare the same property
     * from the same layer, so that only the order of the stylesheet decides
     * which of them applies.
     *
     * A modifier is allowed to overrule the class it modifies - `.c-button` and
     * `.c-button--quiet` are one component saying "and this variant of it", and
     * the whole point of the second is to change what the first set. Anything
     * else that collides is two authors of the same property, neither of them
     * saying so.
     *
     * @param array<string, array<string, list<string>>> $declared as declaredIn() returns
     * @param array<string, list<list<string>>> $together as usedTogetherIn() returns
     *
     * @return list<string> sorted, so that a report reads the same twice
     */
    public static function collisionsBetween(array $declared, array $together): array
    {
        $found = [];
        foreach ($together as $where => $lists) {
            foreach ($lists as $list) {
                foreach (self::pairsOf($list) as [$one, $other]) {
                    if (self::modifies($one, $other) || self::modifies($other, $one)) {
                        continue;
                    }

                    foreach ($declared as $layer => $classes) {
                        $shared = array_intersect(
                            $classes[$one] ?? [],
                            $classes[$other] ?? [],
                        );

                        foreach (array_unique($shared) as $property) {
                            $found[] = sprintf(
                                '%s: .%s and .%s both declare %s in @layer %s',
                                $where,
                                $one,
                                $other,
                                $property,
                                $layer,
                            );
                        }
                    }
                }
            }
        }

        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /**
     * The body of every `@layer name { ... }` block in $css, by name.
     *
     * The closing brace is found by counting rather than by matching, because a
     * layer holds rules and a rule holds braces of its own.
     *
     * @return array<string, string>
     */
    private static function layersIn(string $css): array
    {
        preg_match_all('/@layer\s+([a-z-]+)\s*\{/i', $css, $matches, PREG_OFFSET_CAPTURE);

        $layers = [];
        foreach ($matches[0] as $index => [$opening, $at]) {
            $name = $matches[1][$index][0];
            $start = $at + strlen($opening);

            $depth = 1;
            $end = $start;
            for ($cursor = $start, $length = strlen($css); $cursor < $length; $cursor++) {
                $depth += match ($css[$cursor]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };

                if ($depth === 0) {
                    $end = $cursor;

                    break;
                }
            }

            $layers[$name] = substr($css, $start, $end - $start);
        }

        return $layers;
    }

    /**
     * @return list<array{string, string}> selector and declarations, for a body
     *     whose rules do not nest - which base.css's do not
     */
    private static function rulesIn(string $body): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $body, $matches, PREG_SET_ORDER);

        $rules = [];
        foreach ($matches as $rule) {
            $rules[] = [trim($rule[1]), $rule[2]];
        }

        return $rules;
    }

    /**
     * The selectors of $selector that are one bare class and nothing else.
     *
     * @return list<string>
     */
    private static function plainClassesIn(string $selector): array
    {
        $classes = [];
        foreach (explode(',', $selector) as $one) {
            if (preg_match('/^\.([a-z][a-z0-9_-]*)$/i', trim($one), $named) === 1) {
                $classes[] = $named[1];
            }
        }

        return $classes;
    }

    /** @return list<string> */
    private static function propertiesIn(string $declarations): array
    {
        preg_match_all('/(?:^|;)\s*(-{0,2}[a-z][a-z0-9-]*)\s*:/i', $declarations, $matches);

        return $matches[1];
    }

    /**
     * @param list<string> $names
     *
     * @return list<string> the ones that look like a class name, deduplicated
     */
    private static function namesIn(array $names): array
    {
        $kept = [];
        foreach ($names as $name) {
            if (preg_match('/^[a-z][a-z0-9_-]*$/i', $name) === 1) {
                $kept[$name] = true;
            }
        }

        return array_keys($kept);
    }

    /**
     * @param list<string> $list
     *
     * @return list<array{string, string}>
     */
    private static function pairsOf(array $list): array
    {
        $pairs = [];
        foreach ($list as $index => $one) {
            foreach (array_slice($list, $index + 1) as $other) {
                $pairs[] = [$one, $other];
            }
        }

        return $pairs;
    }

    /** Whether $modifier is a variant of $base, as `.c-button--quiet` is of `.c-button`. */
    private static function modifies(string $modifier, string $base): bool
    {
        return str_starts_with($modifier, $base . '--');
    }
}
