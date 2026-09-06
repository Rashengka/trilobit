<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Content\ContentGroup;
use Trilobit\Core\Presentation\Content\ContentGroupRegistry;
use Trilobit\Tests\Architecture\BaseCssHoldsNoLiteralsTest;

/**
 * The style guide documents what the stylesheet does, and not the other way
 * round.
 *
 * This is the half of decision D5 that a component gets for free and a native
 * element does not. A component that is not there fails to render and the page
 * says so; a paragraph about <mark> renders perfectly whether or not anything
 * ever styled <mark>, and then the style guide has quietly turned from a gate
 * into a wish list - which is the failure
 * .ai/plans/01d-design-system.md warns about under N-B.
 *
 * So every selector a group claims has to be a selector assets/base.css carries.
 * The other direction - that a registered group is actually shown - is
 * Trilobit\Tests\Template\StyleguideShowsEveryContentGroupTest.
 *
 * Prove it works by taking the `hr` rule out of base.css and watching this fail.
 */
#[CoversClass(ContentGroupRegistry::class)]
#[CoversClass(ContentGroup::class)]
final class ContentGroupRegistryTest extends TestCase
{
    /** @return iterable<string, array{ContentGroup}> */
    public static function registered(): iterable
    {
        foreach (new ContentGroupRegistry()->all() as $group) {
            yield $group->name => [$group];
        }
    }

    #[DataProvider('registered')]
    public function testEverySelectorItClaimsIsInTheStylesheet(ContentGroup $group): void
    {
        $declarations = BaseCssHoldsNoLiteralsTest::declarations();

        foreach ($group->selectors as $selector) {
            self::assertMatchesRegularExpression(
                $this->asRule($selector),
                $declarations,
                sprintf(
                    'the content group %s says the design system styles %s, and assets/base.css has no '
                    . 'rule for it - so the style guide would be describing something that is not there',
                    $group->name,
                    $selector,
                ),
            );
        }
    }

    #[DataProvider('registered')]
    public function testItHasSomethingToShow(ContentGroup $group): void
    {
        self::assertNotSame([], $group->selectors, $group->name . ' claims no selector');
        self::assertNotSame([], $group->variants, $group->name . ' declares no variant');
        self::assertSame(
            array_values(array_unique($group->variants)),
            $group->variants,
            $group->name . ' lists the same variant twice',
        );
    }

    public function testNamesAreUnique(): void
    {
        $names = new ContentGroupRegistry()->names();

        self::assertSame(array_values(array_unique($names)), $names);
    }

    /**
     * The selector as it appears at the head of a rule: at the start of a line
     * or after something that separates selectors, and followed by either the
     * comma of a selector list or the brace of the block.
     *
     * Written this narrowly on purpose. A plain substring search would find
     * `table` inside `.c-table` and `code` inside a class called `code-block`,
     * and a group would then pass by naming an element nobody had styled.
     */
    private function asRule(string $selector): string
    {
        return '/(?:^|[\s,])' . preg_quote($selector, '/') . '\s*[,{]/m';
    }
}
