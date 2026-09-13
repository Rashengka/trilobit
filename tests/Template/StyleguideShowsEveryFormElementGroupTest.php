<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Form\FormElementGroup;
use Trilobit\Core\Presentation\Form\FormElementRegistry;

/**
 * The native elements of a form are catalogued the way the components and the
 * elements of running text are: a registered group has one section on some
 * page of the style guide, and every variant it claims is one you can look at.
 *
 * The same shape as Trilobit\Tests\Template\StyleguideShowsEveryContentGroupTest,
 * asked of the attribute the Forms pages mark their sections with. It is a
 * separate attribute and a separate gate on purpose: a group of form elements
 * counted as a content group would pass that gate by being in the wrong
 * register.
 */
#[CoversNothing]
final class StyleguideShowsEveryFormElementGroupTest extends TestCase
{
    #[DataProviderExternal(FormElementRegistryTest::class, 'registered')]
    public function testItHasASection(FormElementGroup $group): void
    {
        self::assertSame(
            [],
            StyleguideSpecimens::missing([$group->name], $this->shown()),
            sprintf(
                '%s is a registered group of form elements and no page of the style guide shows anything of '
                . 'it - the pages looked at were %s. Add a section to the page the guide lists it on.',
                $group->name,
                implode(', ', array_keys(StyleguideSpecimens::everyPage())),
            ),
        );
    }

    #[DataProviderExternal(FormElementRegistryTest::class, 'registered')]
    public function testEveryVariantIsShown(FormElementGroup $group): void
    {
        $places = $this->shown()[$group->name] ?? [];
        self::assertCount(
            1,
            $places,
            sprintf('%s is shown in %d sections of the style guide rather than in one', $group->name, count($places)),
        );

        self::assertSame(
            $group->variants,
            $places[0]['variants'],
            sprintf('the specimens of %s and its registered variants do not match', $group->name),
        );
    }

    /** Nothing is on any page that is not in the register, either. */
    public function testEverySectionBelongsToARegisteredGroup(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(array_keys($this->shown()), new FormElementRegistry()->names())),
        );
    }

    /** @return array<string, list<array{page: string, variants: list<string>}>> */
    private function shown(): array
    {
        return StyleguideSpecimens::shownIn(StyleguideSpecimens::everyPage(), StyleguideSpecimens::FORM);
    }
}
