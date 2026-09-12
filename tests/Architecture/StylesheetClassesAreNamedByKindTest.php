<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Decision D3 as a mechanism: every class assets/base.css styles says by its
 * prefix what kind of thing it is - a layout primitive, a component, the style
 * guide's own furniture or a utility.
 *
 * The prefix is what the rest of the design system keys on: the component
 * register is compared with the c-* rules, the layout gate reads the l-* ones.
 * A class with no prefix is a class none of them sees, and the first one
 * written "because it is only small" is how a component nobody registered
 * gets into the stylesheet.
 *
 * **Other people's markup is the exception, and it is named.** A library that
 * writes its own class names into the page - a highlighter wrapping every
 * token of code in a span - cannot be told to prefix them, and styling it
 * means styling those names. So each such library is listed here with the
 * classes of its that base.css may style, and nothing else is let through: a
 * list per library rather than one pool, so that a second library adds a line
 * of its own and removing a library takes its classes with it. A class listed
 * and no longer styled fails too, so the list cannot outlive what it excuses.
 */
#[CoversNothing]
final class StylesheetClassesAreNamedByKindTest extends TestCase
{
    /** l-* layout, c-* components, sg-* the style guide's furniture, u-* utilities. */
    private const array PREFIXES = ['l-', 'c-', 'sg-', 'u-'];

    /**
     * Library => the classes it writes into the page that base.css styles.
     *
     * prismjs: the highlighter of the style guide's code (assets/styleguide.ts)
     * wraps every token in `<span class="token TYPE">`, TYPE being what its
     * grammar calls the token or an alias of it.
     *
     * @var array<string, list<string>>
     */
    private const array LIBRARIES = [
        'prismjs' => [
            'token',
            'comment', 'prolog', 'doctype', 'cdata',
            'punctuation',
            'tag', 'keyword', 'important', 'selector', 'atrule', 'rule',
            'attr-name', 'property', 'function', 'class-name', 'builtin',
            'string', 'attr-value', 'char', 'regex', 'url',
            'number', 'boolean', 'constant', 'symbol', 'variable',
            'operator', 'entity',
            'bold', 'italic',
        ],
    ];

    public function testEveryClassItStylesSaysWhatKindItIs(): void
    {
        self::assertSame([], $this->unnamedIn(BaseCssHoldsNoLiteralsTest::declarations(), $this->excused()));
    }

    /** An exception nothing needs any more is one somebody will lean on later for something else. */
    public function testEveryClassALibraryIsExcusedForIsStyled(): void
    {
        $styled = $this->classesIn(BaseCssHoldsNoLiteralsTest::declarations());

        foreach (self::LIBRARIES as $library => $classes) {
            self::assertSame(
                [],
                array_values(array_diff($classes, $styled)),
                sprintf('base.css no longer styles these classes of %s, so they need no exception', $library),
            );
        }
    }

    public function testTheRuleReportsAClassWithoutAPrefix(): void
    {
        self::assertSame(
            ['card'],
            $this->unnamedIn('@layer components { .c-card { gap: 0 } .card:hover, .l-grid > .card { gap: 0 } }', []),
        );
    }

    public function testTheRuleLetsAnExcusedClassThrough(): void
    {
        self::assertSame([], $this->unnamedIn('pre .token.comment { color: inherit }', ['token', 'comment']));
    }

    /** A number with a decimal point in a selector is not a class. */
    public function testTheRuleReadsOnlySelectors(): void
    {
        self::assertSame(
            [],
            $this->unnamedIn('@media (min-resolution: 1.5dppx) { .c-card { line-height: 1.5; margin: .5em } }', []),
        );
    }

    /**
     * Every class styled in $css that has none of the prefixes and is not
     * excused, once each, in the order found.
     *
     * @param list<string> $excused
     *
     * @return list<string>
     */
    private function unnamedIn(string $css, array $excused): array
    {
        return array_values(array_filter(
            $this->classesIn($css),
            static function (string $class) use ($excused): bool {
                foreach (self::PREFIXES as $prefix) {
                    if (str_starts_with($class, $prefix)) {
                        return false;
                    }
                }

                return !in_array($class, $excused, true);
            },
        ));
    }

    /**
     * Every class named in a selector of $css. Only what stands before a `{`
     * is read, so a value such as `.5em` in a declaration is never taken for
     * a class.
     *
     * @return list<string>
     */
    private function classesIn(string $css): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/([^{};]+)\{/', $css, $selectors);

        $classes = [];
        foreach ($selectors[1] as $selector) {
            preg_match_all('/\.(-?[_a-zA-Z][\w-]*)/', $selector, $names);
            foreach ($names[1] as $name) {
                $classes[$name] = true;
            }
        }

        return array_keys($classes);
    }

    /** @return list<string> */
    private function excused(): array
    {
        return array_merge(...array_values(self::LIBRARIES));
    }
}
