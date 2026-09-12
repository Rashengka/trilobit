<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\Component;
use Trilobit\Core\Presentation\Component\ComponentRegistry;

/**
 * Decision D5, second half: a registered component has a specimen on some page
 * of the style guide, and every variant it claims is one you can actually look
 * at.
 *
 * The claim is made against the rendered pages rather than against the
 * template source, because a section that does not render - a mistyped block
 * name, a component whose parameters changed - is exactly the case a
 * source-level check would pass. It is what turns the style guide from
 * documentation into a gate: a new component fails `composer check` until
 * somebody has shown it.
 *
 * It asks every page the router sends to the style guide, and not one of them:
 * see Trilobit\Tests\Template\StyleguideSpecimens for why that is the only
 * version of the question that survives the guide being split into pages. The
 * last two cases run the rule over pages built to fail it, because a rule that
 * reports nothing reads the same whether it works or looks in the wrong place.
 */
#[CoversNothing]
final class StyleguideShowsEveryComponentTest extends TestCase
{
    #[DataProviderExternal(ComponentRegistryTest::class, 'registered')]
    public function testItHasASpecimen(Component $component): void
    {
        self::assertSame(
            [],
            StyleguideSpecimens::missing([$component->name], $this->shown()),
            sprintf(
                '%s is registered and no page of the style guide shows a specimen of it - the pages looked at '
                . 'were %s. Add one to the page the guide lists it on.',
                $component->name,
                implode(', ', array_keys(StyleguideSpecimens::everyPage())),
            ),
        );
    }

    /**
     * Shown once, on one page, with every variant. Twice would be two
     * specimens to keep alike, and for c-preference-switcher two controls for
     * every answer on one page.
     */
    #[DataProviderExternal(ComponentRegistryTest::class, 'registered')]
    public function testEveryVariantIsShown(Component $component): void
    {
        $places = $this->shown()[$component->name] ?? [];
        self::assertCount(
            1,
            $places,
            sprintf('%s is shown in %d sections of the style guide rather than in one', $component->name, count($places)),
        );

        self::assertSame(
            $component->variants,
            $places[0]['variants'],
            sprintf('the specimens of %s and its registered variants do not match', $component->name),
        );
    }

    /** Nothing is on any page that is not in the register, either. */
    public function testEverySpecimenBelongsToARegisteredComponent(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(array_keys($this->shown()), new ComponentRegistry()->names())),
        );
    }

    /**
     * The rule above, run over a guide in which one of two components has no
     * specimen anywhere: it has to name that one and only that one.
     */
    public function testTheRuleReportsAComponentNoPageShows(): void
    {
        $pages = [
            '/_styleguide' => $this->page('<main><p>The way into every page.</p></main>'),
            '/_styleguide/components/first' => $this->page(
                '<section data-styleguide-component="c-first"><div data-styleguide-variant="default"></div></section>',
            ),
        ];

        self::assertSame(
            ['c-second'],
            StyleguideSpecimens::missing(
                ['c-first', 'c-second'],
                StyleguideSpecimens::shownIn($pages, StyleguideSpecimens::COMPONENT),
            ),
        );
    }

    /**
     * And over the guide the rule could be "repaired" into: nothing but a
     * front page of links. Every component is then missing, which is the
     * failure that repair has to produce rather than a quiet pass.
     */
    public function testTheRuleReportsEveryComponentWhenOnlyTheFrontPageIsAskedAbout(): void
    {
        $names = new ComponentRegistry()->names();

        self::assertSame(
            $names,
            StyleguideSpecimens::missing(
                $names,
                StyleguideSpecimens::shownIn(
                    ['/_styleguide' => $this->page('<main><nav><a href="#">Components</a></nav></main>')],
                    StyleguideSpecimens::COMPONENT,
                ),
            ),
        );
    }

    /** @return array<string, list<array{page: string, variants: list<string>}>> */
    private function shown(): array
    {
        return StyleguideSpecimens::shownIn(StyleguideSpecimens::everyPage(), StyleguideSpecimens::COMPONENT);
    }

    private function page(string $body): HTMLDocument
    {
        return HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $body . '</body></html>', LIBXML_NOERROR);
    }
}
