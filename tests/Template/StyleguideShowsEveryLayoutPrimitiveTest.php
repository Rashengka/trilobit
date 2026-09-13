<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Tests\Architecture\BaseCssHoldsNoLiteralsTest;

/**
 * Every layout primitive assets/base.css declares is shown on some page of the
 * style guide, once, with every class it has.
 *
 * The components and the native elements are held by a register each. The
 * layout primitives have none, and on purpose: a register pays for itself where
 * something is drawn whether or not anybody styled it - a <mark> is on the page
 * either way - and a primitive is the opposite, drawn only where somebody wrote
 * its class (.ai/plans/13-styleguide-layout.md, part 3). So the gate runs one
 * way only, from the stylesheet to the guide: the list of primitives is read out
 * of base.css itself, every class of it has to be carried inside a specimen of
 * the section naming its primitive, and there is no second list to keep beside
 * the first.
 *
 * What does not count is spelled out in StyleguideSpecimens::primitivesShownIn():
 * the primitives every page is already assembled with, and a section that names
 * a primitive and shows nothing of it. The last two cases run the rule over
 * pages built to make exactly those two mistakes, because a gate that reports
 * nothing reads the same whether it works or looks in the wrong place.
 *
 * Prove it works by taking the specimen marked 'loose' out of
 * src/Core/Presentation/Styleguide/pages/layout/stack.latte and watching
 * testEveryClassIsShownInASpecimenOfItsPrimitive fail for l-stack--loose - the
 * page still shows l-stack, and the frame around it is still a loose stack,
 * and neither of them counts; then put it back.
 */
#[CoversNothing]
final class StyleguideShowsEveryLayoutPrimitiveTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function declaredClasses(): iterable
    {
        foreach (self::declaredIn(BaseCssHoldsNoLiteralsTest::declarations()) as $class) {
            yield $class => [$class];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function declaredPrimitives(): iterable
    {
        foreach (self::primitives() as $primitive) {
            yield $primitive => [$primitive];
        }
    }

    /**
     * Every class of a layout primitive the given stylesheet has a rule for,
     * in the order it first appears. Given the stylesheet with its comments
     * taken out, so that a class named in a sentence is not taken for a rule.
     *
     * @return list<string>
     */
    public static function declaredIn(string $css): array
    {
        preg_match_all('/(?<![\w-])\.(l-[a-z0-9-]*[a-z0-9](?:__[a-z0-9-]*[a-z0-9])?)(?![\w-])/', $css, $matches);

        return array_values(array_unique($matches[1]));
    }

    #[DataProvider('declaredClasses')]
    public function testEveryClassIsShownInASpecimenOfItsPrimitive(string $class): void
    {
        self::assertSame(
            [],
            $this->missing([$class], StyleguideSpecimens::primitivesShownIn(StyleguideSpecimens::everyPage())),
            sprintf(
                '%s is declared in assets/base.css and no specimen in a section marked %s="%s" carries it - the '
                . 'pages looked at were %s. Show it on the page of the Layout group about %s.',
                $class,
                StyleguideSpecimens::LAYOUT,
                StyleguideSpecimens::primitiveOf($class),
                implode(', ', array_keys(StyleguideSpecimens::everyPage())),
                StyleguideSpecimens::primitiveOf($class),
            ),
        );
    }

    /** Shown once: two sections about one primitive would be two specimens to keep alike. */
    #[DataProvider('declaredPrimitives')]
    public function testEveryPrimitiveHasOneSection(string $primitive): void
    {
        $places = StyleguideSpecimens::shownIn(StyleguideSpecimens::everyPage(), StyleguideSpecimens::LAYOUT)[$primitive] ?? [];

        self::assertCount(
            1,
            $places,
            sprintf(
                '%s is shown in %d sections of the style guide rather than in one: %s',
                $primitive,
                count($places),
                implode(', ', array_column($places, 'page')),
            ),
        );
    }

    /**
     * And no section is about a primitive base.css does not have. A specimen of
     * a class nobody wrote a rule for would look exactly like one of a class
     * somebody did, and the guide would be documenting what does not exist.
     */
    public function testEverySectionNamesAPrimitiveTheStylesheetDeclares(): void
    {
        $sections = StyleguideSpecimens::shownIn(StyleguideSpecimens::everyPage(), StyleguideSpecimens::LAYOUT);

        self::assertSame([], array_values(array_diff(array_keys($sections), self::primitives())));
    }

    /** Elements and modifiers are classes of their own; a class named in a value or a longer name is not. */
    public function testTheStylesheetIsReadForEveryClassOfAPrimitive(): void
    {
        self::assertSame(
            ['l-shell', 'l-shell__nav-hold', 'l-stack--loose', 'l-grid'],
            self::declaredIn(
                '.l-shell { } .l-shell__nav-hold > .c-nav { } .l-stack--loose, .c-signpost { } '
                . '.l-grid, .c-l-grid { } .l-shell { }',
            ),
        );
    }

    /**
     * The rule, run over a guide in which one primitive is shown and the other
     * is only used - by the frame every page is drawn in. The frame's use is
     * not a specimen, so the second primitive is missing.
     */
    public function testTheRuleReportsAPrimitiveThatIsOnlyUsedInPassing(): void
    {
        $pages = [
            '/_styleguide/layout/grid' => $this->page(
                '<main class="l-stack">'
                . '<section data-styleguide-layout="l-grid">'
                . '<div data-styleguide-variant="default"><div class="l-grid"></div></div>'
                . '</section></main>',
            ),
        ];

        self::assertSame(
            ['l-stack'],
            $this->missing(['l-grid', 'l-stack'], StyleguideSpecimens::primitivesShownIn($pages)),
        );
    }

    /**
     * And over the guide the rule could be "repaired" into: a front page
     * carrying the marks and nothing to look at - one section empty, one
     * holding the class outside any specimen. Both are still missing, which is
     * the failure that repair has to produce rather than a quiet pass.
     */
    public function testTheRuleIsNotSatisfiedByMarksOnAFrontPageWithNoSpecimen(): void
    {
        $pages = [
            '/_styleguide' => $this->page(
                '<main><nav><a href="#">Layout</a></nav>'
                . '<section data-styleguide-layout="l-grid"></section>'
                . '<section data-styleguide-layout="l-stack"><div class="l-stack"></div></section>'
                . '</main>',
            ),
        ];

        self::assertSame(
            ['l-grid', 'l-stack'],
            $this->missing(['l-grid', 'l-stack'], StyleguideSpecimens::primitivesShownIn($pages)),
        );
    }

    /**
     * The primitives - the blocks the declared classes belong to.
     *
     * @return list<string>
     */
    private static function primitives(): array
    {
        return array_values(array_unique(array_map(
            StyleguideSpecimens::primitiveOf(...),
            self::declaredIn(BaseCssHoldsNoLiteralsTest::declarations()),
        )));
    }

    /**
     * @param list<string> $classes
     * @param array<string, list<string>> $shown as StyleguideSpecimens::primitivesShownIn() returns it
     *
     * @return list<string>
     */
    private function missing(array $classes, array $shown): array
    {
        return array_values(array_filter($classes, static fn(string $class): bool => ($shown[$class] ?? []) === []));
    }

    private function page(string $body): HTMLDocument
    {
        return HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $body . '</body></html>', LIBXML_NOERROR);
    }
}
