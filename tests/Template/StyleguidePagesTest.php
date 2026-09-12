<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Nette\Utils\Finder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\ComponentRegistry;
use Trilobit\Core\Presentation\Content\ContentGroupRegistry;
use Trilobit\Core\Presentation\Styleguide\StyleguideGroup;
use Trilobit\Core\Presentation\Styleguide\StyleguidePage;
use Trilobit\Core\Presentation\Styleguide\StyleguidePages;

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
 * That every registered component and content group is actually shown on some
 * page is a claim about rendered pages, and is made by
 * Trilobit\Tests\Template\StyleguideShowsEveryComponentTest and its twin.
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
        return new StyleguidePages();
    }
}
