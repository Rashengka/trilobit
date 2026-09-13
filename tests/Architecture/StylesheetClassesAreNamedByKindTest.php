<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
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
 * writes its own class names into the page cannot be told to prefix them, and
 * styling it means styling those names. So each such library is listed here
 * with the classes of its that base.css may style, and nothing else is let
 * through: a list per library rather than one pool, so that a second library
 * adds a line of its own and removing a library takes its classes with it. A
 * class listed and no longer styled fails too, so the list cannot outlive what
 * it excuses.
 *
 * **A library is held to what it writes.** A rule
 * about one of its class names is a rule about its behaviour, and it holds only
 * as long as the library still writes that name - a name it stopped writing is
 * a state quietly no longer drawn, with nothing failing and the page still
 * looking finished. So every library names the version its list was read off,
 * which package.json has to pin; and one that writes its names as literals
 * also names the bundle its code ends up in, which has to carry every name on
 * the list.
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
     * tom-select: draws c-combobox in front of a select (assets/combobox.ts).
     * The classes it lets us choose carry the component's name; these it
     * writes itself - on the wrapper while the list is open, refused or
     * disabled, on the option the arrows are on and the one chosen, around the
     * part of an option that matches what was typed, and on the select it
     * hides. Read off src/tom-select.ts and src/contrib/highlight.ts.
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
        'tom-select' => [
            'dropdown-active', 'invalid', 'disabled',
            'active', 'selected',
            'highlight',
            'ts-hidden-accessible',
        ],
    ];

    /**
     * Library => the version its list above was read off, which package.json
     * has to pin: a new version is read again before the build takes it.
     *
     * @var array<string, string>
     */
    private const array VERSIONS = [
        'prismjs' => '1.30.0',
        'tom-select' => '2.6.2',
    ];

    /**
     * Library => the bundle its code ends up in, which has to carry every name
     * on its list as the literal the library writes.
     *
     * Only a library that writes its names as literals is here. prismjs is
     * not: it builds `token TYPE` at run time out of the keys of its grammars,
     * which a minifier writes unquoted, so its names are held by the version
     * above alone.
     *
     * @var array<string, string>
     */
    private const array BUNDLES = [
        'tom-select' => 'www/build/app.js',
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

    public function testEveryListIsReadOffTheVersionTheBuildPins(): void
    {
        $package = Json::decode(FileSystem::read($this->root() . '/package.json'), forceArrays: true);
        self::assertIsArray($package);
        self::assertIsArray($package['dependencies'] ?? null, 'package.json has no dependencies');

        foreach (self::VERSIONS as $library => $version) {
            self::assertSame(
                $version,
                $package['dependencies'][$library] ?? null,
                sprintf(
                    'package.json no longer pins %s to %s, the version its classes here were read off. Read the '
                    . 'classes the new version writes, then change the list and the version in this file.',
                    $library,
                    $version,
                ),
            );
        }
    }

    public function testEveryBundledLibraryWritesTheClassesItIsExcusedFor(): void
    {
        foreach (self::BUNDLES as $library => $bundle) {
            self::assertSame(
                [],
                $this->missingFrom(FileSystem::read($this->root() . '/' . $bundle), self::LIBRARIES[$library]),
                sprintf('%s does not carry these classes of %s, so nothing on the page writes them', $bundle, $library),
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
        self::assertSame(
            [],
            $this->unnamedIn('.c-combobox.dropdown-active .c-combobox__option.active { color: inherit }', [
                'dropdown-active',
                'active',
            ]),
        );
    }

    /** A number with a decimal point in a selector is not a class. */
    public function testTheRuleReadsOnlySelectors(): void
    {
        self::assertSame(
            [],
            $this->unnamedIn('@media (min-resolution: 1.5dppx) { .c-card { line-height: 1.5; margin: .5em } }', []),
        );
    }

    /** The bundle check, run over a bundle that writes one of the two names asked about. */
    public function testTheBundleCheckReportsANameNothingWrites(): void
    {
        self::assertSame(
            ['is-open'],
            $this->missingFrom(
                'e.classList.toggle("dropdown-active",t),n.className="highlight is-openly";',
                ['dropdown-active', 'is-open'],
            ),
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

    /**
     * The names in $names that $bundle carries nowhere as a string of its own,
     * or as one word of a string of several - which is how a library writes
     * them, and the one form a minifier leaves alone.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function missingFrom(string $bundle, array $names): array
    {
        return array_values(array_filter(
            $names,
            static fn(string $name): bool => preg_match(
                sprintf('/(?<=[\'"`\s])%s(?=[\'"`\s])/', preg_quote($name, '/')),
                $bundle,
            ) !== 1,
        ));
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
