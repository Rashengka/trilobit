<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Nette\Utils\Finder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\Component;
use Trilobit\Core\Presentation\Component\ComponentRegistry;
use Trilobit\Core\Presentation\Content\ContentGroup;
use Trilobit\Core\Presentation\Content\ContentGroupRegistry;
use Trilobit\Core\Presentation\Form\FormElementGroup;
use Trilobit\Core\Presentation\Form\FormElementRegistry;
use Trilobit\Core\Presentation\Styleguide\StyleguideGroup;
use Trilobit\Core\Presentation\Styleguide\StyleguidePage;
use Trilobit\Core\Presentation\Styleguide\StyleguidePages;
use Trilobit\Core\Routing\StyleguideRoutes;

/**
 * The list of the style guide's pages and the files that draw them say the
 * same thing, in both directions.
 *
 * The list is the one place the guide's pages are written down: the routes are
 * made from it, and so are the menu down the side of every page and the front
 * page of the guide. A page listed with no file behind it would be a route
 * that fails when somebody follows it; a file with no page listed would be a
 * page nobody can reach and nothing checks. Both fail here, the same way
 * Trilobit\Tests\Template\ComponentRegistryTest holds the components and their
 * directory together.
 *
 * What a page says it shows is held to what it draws, and every registered
 * component and group of native elements has one page saying it shows it.
 * That every one of them is actually shown somewhere is still a claim about
 * rendered pages, made by Trilobit\Tests\Template\StyleguideShowsEveryComponentTest
 * and its twin.
 */
#[CoversClass(StyleguidePages::class)]
#[CoversClass(StyleguidePage::class)]
#[CoversClass(StyleguideGroup::class)]
final class StyleguidePagesTest extends TestCase
{
    /** @return iterable<string, array{StyleguidePage}> */
    public static function listed(): iterable
    {
        foreach (self::pages()->pages() as $page) {
            yield $page->path() => [$page];
        }
    }

    /** @return iterable<string, array{string}> the file, relative to StyleguidePages::directory() */
    public static function pageFiles(): iterable
    {
        $directory = StyleguidePages::directory();

        foreach (Finder::findFiles('*.latte')->from($directory) as $file) {
            $relative = substr((string) $file, strlen($directory) + 1);
            yield $relative => [$relative];
        }
    }

    #[DataProvider('listed')]
    public function testItsFileExists(StyleguidePage $page): void
    {
        self::assertFileExists(
            StyleguidePages::directory() . '/' . $page->file(),
            sprintf('%s is a page of the style guide and nothing draws it', $page->path()),
        );
    }

    #[DataProvider('pageFiles')]
    public function testEveryFileIsAListedPage(string $file): void
    {
        self::assertContains(
            $file,
            array_map(static fn(StyleguidePage $page): string => $page->file(), self::pages()->pages()),
            sprintf(
                '%s draws a page of the style guide that %s does not list, so no route leads to it and no '
                . 'gate looks at it.',
                $file,
                StyleguidePages::class,
            ),
        );
    }

    /** Two plain segments, the group and the page, because that is the address. */
    #[DataProvider('listed')]
    public function testItsAddressIsAGroupAndAPage(StyleguidePage $page): void
    {
        self::assertMatchesRegularExpression('#^[a-z0-9]+(?:-[a-z0-9]+)*/[a-z0-9]+(?:-[a-z0-9]+)*$#', $page->path());
    }

    public function testNoTwoPagesShareAnAddress(): void
    {
        $paths = array_map(static fn(StyleguidePage $page): string => $page->path(), self::pages()->pages());

        self::assertSame(array_values(array_unique($paths)), $paths);
    }

    public function testNoTwoGroupsShareAName(): void
    {
        $names = array_map(static fn(StyleguideGroup $group): string => $group->name, self::pages()->groups());

        self::assertSame(array_values(array_unique($names)), $names);
    }

    /** A page's address begins with the group it is listed under, and not with another. */
    public function testEveryPageBelongsToTheGroupItIsListedUnder(): void
    {
        foreach (self::pages()->groups() as $group) {
            foreach ($group->pages as $page) {
                self::assertSame($group->name, $page->group, sprintf('%s is listed under %s', $page->path(), $group->name));
            }
        }
    }

    /** What a page says it shows is something the design system has. */
    #[DataProvider('listed')]
    public function testWhatItShowsIsRegistered(StyleguidePage $page): void
    {
        self::assertSame([], array_values(array_diff($page->components, new ComponentRegistry()->names())));
        self::assertSame([], array_values(array_diff($page->contentGroups, new ContentGroupRegistry()->names())));
        self::assertSame([], array_values(array_diff($page->formElements, new FormElementRegistry()->names())));
    }

    /**
     * Every registered component is one page of the guide, the way Bootstrap
     * gives every component a page: the menu offers it, and there is one place
     * to look for it.
     */
    #[DataProviderExternal(ComponentRegistryTest::class, 'registered')]
    public function testEveryComponentIsListedOnExactlyOnePage(Component $component): void
    {
        $on = array_filter(
            self::pages()->pages(),
            static fn(StyleguidePage $page): bool => $page->shows($component->name),
        );

        self::assertCount(
            1,
            $on,
            sprintf('%s is a registered component and %d pages of the style guide say they show it', $component->name, count($on)),
        );
    }

    /**
     * Every registered group of native elements is one page of the guide, so
     * that the menu offers it and there is one place to look for it.
     */
    #[DataProviderExternal(ContentGroupRegistryTest::class, 'registered')]
    public function testEveryContentGroupIsListedOnExactlyOnePage(ContentGroup $group): void
    {
        $on = array_filter(
            self::pages()->pages(),
            static fn(StyleguidePage $page): bool => in_array($group->name, $page->contentGroups, true),
        );

        self::assertCount(
            1,
            $on,
            sprintf('%s is a registered content group and %d pages of the style guide say they show it', $group->name, count($on)),
        );
    }

    /** Every registered group of native form elements is one page of the guide, on the same terms. */
    #[DataProviderExternal(FormElementRegistryTest::class, 'registered')]
    public function testEveryFormElementGroupIsListedOnExactlyOnePage(FormElementGroup $group): void
    {
        $on = array_filter(
            self::pages()->pages(),
            static fn(StyleguidePage $page): bool => in_array($group->name, $page->formElements, true),
        );

        self::assertCount(
            1,
            $on,
            sprintf('%s is a registered group of form elements and %d pages of the style guide say they show it', $group->name, count($on)),
        );
    }

    /**
     * What the list says a page shows is what the page draws, and nothing
     * besides - so the menu cannot send somebody to a page for a component
     * that is on another one.
     */
    public function testEveryPageShowsWhatTheListSaysItDoesAndNothingElse(): void
    {
        $everyPage = StyleguideSpecimens::everyPage();

        foreach (StyleguideSpecimens::pages()->pages() as $page) {
            $path = '/' . StyleguideRoutes::PATH . '/' . $page->path();
            self::assertArrayHasKey($path, $everyPage, sprintf('%s is listed and was not rendered', $path));

            $drawn = [$path => $everyPage[$path]];
            self::assertSame(
                $page->components,
                array_keys(StyleguideSpecimens::shownIn($drawn, StyleguideSpecimens::COMPONENT)),
                sprintf('the components %s draws are not the ones the list says it shows', $path),
            );
            self::assertSame(
                $page->contentGroups,
                array_keys(StyleguideSpecimens::shownIn($drawn, StyleguideSpecimens::CONTENT)),
                sprintf('the content groups %s draws are not the ones the list says it shows', $path),
            );
            self::assertSame(
                $page->formElements,
                array_keys(StyleguideSpecimens::shownIn($drawn, StyleguideSpecimens::FORM)),
                sprintf('the groups of form elements %s draws are not the ones the list says it shows', $path),
            );
        }
    }

    #[DataProvider('listed')]
    public function testItIsFoundByItsAddress(StyleguidePage $page): void
    {
        self::assertSame($page->path(), self::pages()->find($page->group, $page->slug)?->path());
    }

    public function testAnAddressNobodyListedFindsNothing(): void
    {
        self::assertNull(self::pages()->find('components', 'a-page-nobody-wrote'));
    }

    private static function pages(): StyleguidePages
    {
        return new StyleguidePages(new ContentGroupRegistry(), new FormElementRegistry(), new ComponentRegistry());
    }
}
