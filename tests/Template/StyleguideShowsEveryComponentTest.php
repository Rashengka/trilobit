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
use Trilobit\Core\Presentation\Component\Component;
use Trilobit\Core\Presentation\Component\ComponentRegistry;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Combination\Build;

/**
 * Decision D5, second half: a registered component has a specimen on the style
 * guide page, and every variant it claims is one you can actually look at.
 *
 * The claim is made against the rendered page rather than against the template
 * source, because a section that does not render - a mistyped block name, a
 * component whose parameters changed - is exactly the case a source-level check
 * would pass. It is what turns the style guide from documentation into a gate:
 * a new component fails `composer check` until somebody has shown it.
 *
 * The page is rendered through the real container with the style guide switched
 * on explicitly, because its default is %debugMode% and this suite has to say
 * the same thing on a developer's machine and in a fresh checkout.
 */
#[CoversNothing]
final class StyleguideShowsEveryComponentTest extends TestCase
{
    private static ?HTMLDocument $page = null;

    #[DataProviderExternal(ComponentRegistryTest::class, 'registered')]
    public function testItHasASpecimen(Component $component): void
    {
        self::assertNotNull(
            $this->sectionOf($component),
            sprintf(
                '%s is registered and the style guide shows no specimen of it; add one to %s.',
                $component->name,
                'src/Core/Presentation/Styleguide/templates/Overview/default.latte',
            ),
        );
    }

    #[DataProviderExternal(ComponentRegistryTest::class, 'registered')]
    public function testEveryVariantIsShown(Component $component): void
    {
        $section = $this->sectionOf($component);
        self::assertNotNull($section, $component->name . ' has no specimen at all');

        self::assertSame(
            $component->variants,
            $this->variantsIn($section),
            sprintf('the specimens of %s and its registered variants do not match', $component->name),
        );
    }

    /** Nothing is on the page that is not in the register, either. */
    public function testEverySpecimenBelongsToARegisteredComponent(): void
    {
        $shown = [];
        foreach ($this->page()->querySelectorAll('[data-styleguide-component]') as $section) {
            $shown[] = $section->getAttribute('data-styleguide-component');
        }

        self::assertSame(new ComponentRegistry()->names(), $shown);
    }

    private function sectionOf(Component $component): ?HTMLElement
    {
        $section = $this->page()->querySelector(
            sprintf('[data-styleguide-component="%s"]', $component->name),
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
