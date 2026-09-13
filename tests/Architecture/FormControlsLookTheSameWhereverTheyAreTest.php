<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A form control looks the same whichever arrangement it is drawn in.
 *
 * The forms of .ai/plans/19 come in three shapes - side by side, stacked, label
 * beside control - and the shape is a layer on top of the controls, never a
 * reason for a control to look different. An input styled as "an input inside
 * this wrapper" would look one way in the wrapper that happened to be written
 * first and another way, or not at all, in the next; nothing would fail, and
 * two forms on two pages would quietly disagree.
 *
 * So no rule in assets/base.css may reach a form control through something
 * around it. The control is the subject of a selector only when nothing stands
 * in front of it: `select`, `:is(input, select, textarea):disabled` and
 * `label:has(> input[type='checkbox'])` are all fine - the last one styles the
 * label, and the input is only what it asks about - while
 * `.c-field__control :is(input, select, textarea)` is not.
 *
 * Prove it works by writing that last rule back into base.css and watching
 * this fail; the second case below runs the rule over it on purpose.
 */
#[CoversNothing]
final class FormControlsLookTheSameWhereverTheyAreTest extends TestCase
{
    /** The elements of a form this rule is about. A button is not one: c-button is drawn by class. */
    private const array CONTROLS = ['input', 'select', 'textarea', 'label', 'fieldset', 'legend', 'option'];

    public function testNoRuleReachesAControlThroughWhatSurroundsIt(): void
    {
        self::assertSame([], $this->reachedThroughAnAncestor(BaseCssHoldsNoLiteralsTest::declarations()));
    }

    /**
     * The stylesheet passes, so the case above would read the same if the rule
     * looked at nothing. Here it is run over the arrangement it exists to
     * catch - which is the rule this stylesheet carried until the controls
     * were styled by name.
     */
    public function testTheRuleReportsAControlStyledThroughItsWrapper(): void
    {
        self::assertSame(
            ['.c-field__control :is(input, select, textarea)', '.c-form--inline > input'],
            $this->reachedThroughAnAncestor(
                '@layer components { .c-field__control :is(input, select, textarea) { inline-size: 100%; } '
                . '.c-field { display: flex; } .c-form--inline > input { inline-size: auto; } }',
            ),
        );
    }

    /** What the rule lets through, so that it cannot be satisfied by refusing everything. */
    public function testTheRuleLeavesAControlStyledByNameAlone(): void
    {
        self::assertSame(
            [],
            $this->reachedThroughAnAncestor(
                '@layer base { select, textarea { color: red } :is(input, select):disabled { color: red } '
                . "label:has(> input[type='checkbox'], > input[type='radio']) { gap: 0 } "
                . '.c-field__label { color: red } }',
            ),
        );
    }

    /**
     * Every selector in $css whose subject - the part after the last
     * combinator - is a form control, and which has something in front of it.
     *
     * What is inside :has() and inside an attribute selector is taken out of
     * the subject before it is looked at: it is what the rule asks about, not
     * what it styles.
     *
     * @return list<string>
     */
    private function reachedThroughAnAncestor(string $css): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/([^{};]+)\{/', $css, $heads);

        $found = [];
        foreach ($heads[1] as $head) {
            $head = trim($head);
            if ($head === '' || str_starts_with($head, '@')) {
                continue;
            }

            foreach ($this->split($head, ',') as $selector) {
                $compounds = $this->compounds($selector);
                if (count($compounds) < 2) {
                    continue;
                }

                $subject = (string) preg_replace('/:has\((?:[^()]|\([^()]*\))*\)|\[[^\]]*\]/', '', end($compounds));
                if (preg_match('/(?<![\w-])(?:' . implode('|', self::CONTROLS) . ')(?![\w-])/', $subject) === 1) {
                    $found[] = $selector;
                }
            }
        }

        return $found;
    }

    /**
     * $selector cut at every combinator that is not inside brackets.
     *
     * @return list<string>
     */
    private function compounds(string $selector): array
    {
        $normalised = (string) preg_replace('/\s*([>+~])\s*/', ' ', $selector);

        return $this->split($normalised, ' ');
    }

    /**
     * $text cut at every $separator outside parentheses and brackets, pieces
     * trimmed and empty ones dropped.
     *
     * @return list<string>
     */
    private function split(string $text, string $separator): array
    {
        $pieces = [];
        $depth = 0;
        $current = '';

        foreach (str_split($text) as $character) {
            $depth += match ($character) {
                '(', '[' => 1,
                ')', ']' => -1,
                default => 0,
            };

            if ($character === $separator && $depth === 0) {
                $pieces[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $pieces[] = trim($current);

        return array_values(array_filter($pieces, static fn(string $piece): bool => $piece !== ''));
    }
}
