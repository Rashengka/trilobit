<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;

/**
 * Two classes written on one element never both decide the same property with
 * nothing but the order of the stylesheet to settle it.
 *
 * **This rule exists because of a bug nobody could see in either file.** The
 * signpost component carried `class="c-signpost l-grid"`; `.l-grid` made the
 * element a grid and `.c-signpost`, four hundred lines further down and
 * meaning to carry nothing at all, said `display: block`. Same weight, same
 * layer, so the later line won and the front page drew its sections as three
 * stacked blocks with no gap between them. Read on its own each rule is
 * correct, each template is correct, and the failure is in neither of them but
 * in the pair - which is why the check has to be on the pair.
 *
 * **The rule is about which order decides, and that is why layers are allowed
 * through.** `.c-table__caption u-visually-hidden` is exactly the same
 * arrangement on paper: two classes on one element, one of them meaning to
 * overrule the other. It passes because the utility is in a later cascade layer
 * and therefore wins wherever it is written in the file - the decision is
 * declared rather than inherited from where somebody happened to paste it. The
 * same is true of a modifier overruling the class it modifies, which is what a
 * modifier is for.
 *
 * What it does not see is written on
 * Trilobit\Tests\Architecture\StylesheetClasses: selectors weightier than a
 * bare class, and class lists a template does not write out. It also compares
 * property names as written, so a shorthand cancelling a longhand - `border`
 * against `border-color` - goes unreported. **Exit condition:** the first time
 * a shorthand and a longhand of the same property are found on two classes
 * used together; the answer then is a list of which shorthand covers what, and
 * it is not worth writing before it catches something.
 *
 * Prove it works by putting `display: block` back into a `.c-signpost` rule of
 * its own and writing `l-grid` beside it in the template; then take both out
 * again.
 */
#[CoversNothing]
final class ClassesUsedTogetherDoNotCollideTest extends TestCase
{
    public function testNothingUsedTogetherDecidesTheSamePropertyByOrderAlone(): void
    {
        self::assertSame(
            [],
            StylesheetClasses::collisionsBetween($this->declared(), $this->together()),
        );
    }

    /**
     * The application contains no such pair, so the assertion above would read
     * the same if the rule looked in the wrong place or asked the wrong
     * question. Here it is run over exactly the arrangement it exists to catch.
     */
    public function testTheRuleReportsAHookThatCancelsALayoutPrimitive(): void
    {
        self::assertSame(
            ['a template: .c-signpost and .l-grid both declare display in @layer components'],
            StylesheetClasses::collisionsBetween(
                StylesheetClasses::declaredIn(
                    '@layer components { .l-grid { display: grid; gap: 0 } .c-signpost { display: block } }',
                ),
                ['a template' => [['c-signpost', 'l-grid']]],
            ),
        );
    }

    /** A variant of a component is meant to overrule the component. */
    public function testAModifierMayOverruleTheClassItModifies(): void
    {
        self::assertSame(
            [],
            StylesheetClasses::collisionsBetween(
                StylesheetClasses::declaredIn(
                    '@layer components { .c-button { background-color: red } .c-button--quiet { background-color: blue } }',
                ),
                ['a template' => [['c-button', 'c-button--quiet']]],
            ),
        );
    }

    /**
     * A later layer is meant to overrule an earlier one, wherever in the file
     * the two are written. That is the whole difference between a decision and
     * an accident of order, so it has to be measured rather than assumed.
     */
    public function testALaterLayerMayOverruleAnEarlierOne(): void
    {
        self::assertSame(
            [],
            StylesheetClasses::collisionsBetween(
                StylesheetClasses::declaredIn(
                    '@layer components { .c-table__caption { overflow: visible } } '
                        . '@layer utilities { .u-visually-hidden { overflow: hidden } }',
                ),
                ['a template' => [['c-table__caption', 'u-visually-hidden']]],
            ),
        );
    }

    /** A rule run over nothing reports nothing, so there has to be something to run it over. */
    public function testItReadsTheStylesheetAndTheTemplatesItIsAbout(): void
    {
        $declared = $this->declared();
        self::assertArrayHasKey('components', $declared);
        self::assertArrayHasKey('l-grid', $declared['components']);
        self::assertContains('display', $declared['components']['l-grid']);

        $together = $this->together();
        self::assertNotSame([], $together);
        self::assertContains(
            ['l-container', 'l-stack', 'l-stack--loose'],
            array_merge(...array_values($together)),
        );
    }

    /** @return array<string, array<string, list<string>>> */
    private function declared(): array
    {
        return StylesheetClasses::declaredIn(BaseCssHoldsNoLiteralsTest::declarations());
    }

    /** @return array<string, list<list<string>>> */
    private function together(): array
    {
        return StylesheetClasses::usedTogetherIn(Bootstrap::rootDirectory() . '/src');
    }
}
