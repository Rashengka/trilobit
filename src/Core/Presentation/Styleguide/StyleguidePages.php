<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Styleguide;

/**
 * Every page of the style guide, in the groups the menu lists them under.
 *
 * **This is the one place the guide's pages are written down,** and everything
 * that needs to know them reads it: Trilobit\Core\Routing\StyleguideRoutes makes
 * a route of each, the menu down the side of every page and the front page of
 * the guide are drawn from it, and the gates that ask whether every component
 * is shown ask every page the router sends to the guide - which is this list
 * again. A list written three times drifts apart; written once it cannot.
 *
 * The guide used to be one page carrying everything. It is split the way
 * Bootstrap's documentation is, into groups of pages, because a page that grows
 * with every component becomes a page nobody can find anything on.
 *
 * tests/Template/StyleguidePagesTest holds this list and the files under
 * directory() together in both directions.
 */
final class StyleguidePages
{
    /** @var non-empty-list<StyleguideGroup>|null */
    private ?array $groups = null;

    /** Where the files drawing the pages are, one directory per group. */
    public static function directory(): string
    {
        return __DIR__ . '/pages';
    }

    /** @return non-empty-list<StyleguideGroup> in the order the menu lists them */
    public function groups(): array
    {
        return $this->groups ??= [
            new StyleguideGroup(
                'foundations',
                'Foundations',
                'What every component is drawn out of, and the settings a reader changes it with.',
                [
                    // The one page of the guide drawn at a width nobody chose,
                    // and the reason the width is a property of a page rather
                    // than of the class answering it: every page here is the
                    // same action of the same presenter.
                    new StyleguidePage(
                        'foundations',
                        'full-width',
                        'A page that insists',
                        'Drawn at the full width of its region, whichever width is chosen for everything else.',
                        width: 'full',
                    ),
                ],
            ),
        ];
    }

    /** @return non-empty-list<StyleguidePage> */
    public function pages(): array
    {
        $pages = [];
        foreach ($this->groups() as $group) {
            foreach ($group->pages as $page) {
                $pages[] = $page;
            }
        }

        return $pages;
    }

    public function find(string $group, string $slug): ?StyleguidePage
    {
        foreach ($this->pages() as $page) {
            if ($page->group === $group && $page->slug === $slug) {
                return $page;
            }
        }

        return null;
    }
}
