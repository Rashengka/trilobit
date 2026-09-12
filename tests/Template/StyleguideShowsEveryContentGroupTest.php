<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Content\ContentGroup;
use Trilobit\Core\Presentation\Content\ContentGroupRegistry;

/**
 * The native elements are catalogued the way the components are: a registered
 * group has a section on some page of the style guide, and every variant it
 * claims is one you can look at.
 *
 * It is deliberately the same shape as
 * Trilobit\Tests\Template\StyleguideShowsEveryComponentTest, and for the same
 * reasons: the claim is made against the rendered pages rather than against
 * the templates, because a section that fails to render is exactly the case a
 * source-level check would pass; and it is made of every page the router sends
 * to the style guide rather than of one, because one page is the question a
 * split guide stops answering. The rule itself is run over pages built to
 * fail it in that class, and is the same rule here.
 */
#[CoversNothing]
final class StyleguideShowsEveryContentGroupTest extends TestCase
{
    #[DataProviderExternal(ContentGroupRegistryTest::class, 'registered')]
    public function testItHasASection(ContentGroup $group): void
    {
        self::assertSame(
            [],
            StyleguideSpecimens::missing([$group->name], $this->shown()),
            sprintf(
                '%s is a registered content group and no page of the style guide shows anything of it - the '
                . 'pages looked at were %s. Add a section to the page the guide lists it on.',
                $group->name,
                implode(', ', array_keys(StyleguideSpecimens::everyPage())),
            ),
        );
    }

    #[DataProviderExternal(ContentGroupRegistryTest::class, 'registered')]
    public function testEveryVariantIsShown(ContentGroup $group): void
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
            array_values(array_diff(array_keys($this->shown()), new ContentGroupRegistry()->names())),
        );
    }

    /** @return array<string, list<array{page: string, variants: list<string>}>> */
    private function shown(): array
    {
        return StyleguideSpecimens::shownIn(StyleguideSpecimens::everyPage(), StyleguideSpecimens::CONTENT);
    }
}
