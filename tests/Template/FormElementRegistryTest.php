<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Form\FormElementGroup;
use Trilobit\Core\Presentation\Form\FormElementRegistry;
use Trilobit\Tests\Architecture\BaseCssHoldsNoLiteralsTest;

/**
 * The Forms pages of the style guide document what the stylesheet does, and
 * not the other way round.
 *
 * The same claim Trilobit\Tests\Template\ContentGroupRegistryTest makes about
 * the native elements of running text, made about the native elements of a
 * form. An input renders whether or not anything ever styled it, so a specimen
 * of one proves nothing on its own: every selector a group claims has to be a
 * selector assets/base.css carries. The other direction - that every group is
 * actually shown - is Trilobit\Tests\Template\StyleguideShowsEveryFormElementGroupTest.
 *
 * Prove it works by taking the `legend` rule out of base.css and watching this
 * fail.
 */
#[CoversClass(FormElementRegistry::class)]
#[CoversClass(FormElementGroup::class)]
final class FormElementRegistryTest extends TestCase
{
    /** @return iterable<string, array{FormElementGroup}> */
    public static function registered(): iterable
    {
        foreach (new FormElementRegistry()->all() as $group) {
            yield $group->name => [$group];
        }
    }

    #[DataProvider('registered')]
    public function testEverySelectorItClaimsIsInTheStylesheet(FormElementGroup $group): void
    {
        $declarations = BaseCssHoldsNoLiteralsTest::declarations();

        foreach ($group->selectors as $selector) {
            self::assertMatchesRegularExpression(
                $this->asRule($selector),
                $declarations,
                sprintf(
                    'the form element group %s says the design system styles %s, and assets/base.css has no '
                    . 'rule for it - so the style guide would be describing something that is not there',
                    $group->name,
                    $selector,
                ),
            );
        }
    }

    #[DataProvider('registered')]
    public function testItHasSomethingToShow(FormElementGroup $group): void
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
        $names = new FormElementRegistry()->names();

        self::assertSame(array_values(array_unique($names)), $names);
    }

    /**
     * Every element the plan asks the design system to have an opinion about
     * is claimed by some group, so none of them can drop out of the register
     * and take its specimen with it without this saying so.
     */
    public function testEveryNativeControlIsClaimed(): void
    {
        $claimed = implode("\n", array_merge(...array_map(
            static fn(FormElementGroup $group): array => $group->selectors,
            new FormElementRegistry()->all(),
        )));

        foreach (['input', 'select', 'textarea', "'checkbox'", "'radio'", 'label', 'fieldset', 'legend'] as $element) {
            self::assertStringContainsString($element, $claimed, sprintf('no group claims %s', $element));
        }

        foreach ([':focus-visible', ':disabled', "[aria-invalid='true']", ':user-invalid'] as $state) {
            self::assertStringContainsString($state, $claimed, sprintf('no group claims the state %s', $state));
        }
    }

    /**
     * The selector as it appears at the head of a rule - the same narrow match
     * ContentGroupRegistryTest uses, and for the same reason: a substring
     * search would find `select` inside `.c-select` and pass a group naming an
     * element nobody styled.
     */
    private function asRule(string $selector): string
    {
        return '/(?:^|[\s,])' . preg_quote($selector, '/') . '\s*[,{]/m';
    }
}
