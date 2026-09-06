<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\HTMLDocument;
use Dom\HTMLElement;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Presentation\Content\ContentGroup;
use Trilobit\Core\Presentation\Content\ContentGroupRegistry;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Combination\Build;

/**
 * The native elements are catalogued the way the components are: a registered
 * group has a section on the style guide page, and every variant it claims is
 * one you can look at.
 *
 * It is deliberately the same shape as
 * Trilobit\Tests\Template\StyleguideShowsEveryComponentTest, and for the same
 * reason: the claim is made against the rendered page rather than against the
 * template, because a section that fails to render is exactly the case a
 * source-level check would pass.
 */
#[CoversNothing]
final class StyleguideShowsEveryContentGroupTest extends TestCase
{
    private static ?HTMLDocument $page = null;

    #[DataProviderExternal(ContentGroupRegistryTest::class, 'registered')]
    public function testItHasASection(ContentGroup $group): void
    {
        self::assertNotNull(
            $this->sectionOf($group),
            sprintf(
                '%s is a registered content group and the style guide shows nothing of it; add a section '
                . 'to %s.',
                $group->name,
                'src/Core/Presentation/Styleguide/templates/Overview/default.latte',
            ),
        );
    }

    #[DataProviderExternal(ContentGroupRegistryTest::class, 'registered')]
    public function testEveryVariantIsShown(ContentGroup $group): void
    {
        $section = $this->sectionOf($group);
        self::assertNotNull($section, $group->name . ' has no section at all');

        self::assertSame(
            $group->variants,
            $this->variantsIn($section),
            sprintf('the specimens of %s and its registered variants do not match', $group->name),
        );
    }

    /** Nothing is on the page that is not in the register, either. */
    public function testEverySectionBelongsToARegisteredGroup(): void
    {
        $shown = [];
        foreach ($this->page()->querySelectorAll('[data-styleguide-content]') as $section) {
            $shown[] = $section->getAttribute('data-styleguide-content');
        }

        self::assertSame(new ContentGroupRegistry()->names(), $shown);
    }

    private function sectionOf(ContentGroup $group): ?HTMLElement
    {
        $section = $this->page()->querySelector(
            sprintf('[data-styleguide-content="%s"]', $group->name),
        );

        return $section instanceof HTMLElement ? $section : null;
    }

    /** @return list<string> */
    private function variantsIn(HTMLElement $section): array
    {
        $variants = [];
        foreach ($section->querySelectorAll('[data-styleguide-variant]') as $specimen) {
            $variants[] = $specimen->getAttribute('data-styleguide-variant') ?? '';
        }

        return $variants;
    }

    private function page(): HTMLDocument
    {
        return self::$page ??= HTMLDocument::createFromString(
            Build::render(
                Boot::container(
                    ModuleList::of(['cms' => true, 'crm' => true, 'shop' => true], Bootstrap::rootDirectory()),
                    styleguide: true,
                ),
                'Core:Styleguide:Overview',
            ),
            LIBXML_NOERROR,
        );
    }
}
