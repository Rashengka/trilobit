<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Styleguide;

use Trilobit\Core\Presentation\Component\Component;
use Trilobit\Core\Presentation\Component\ComponentRegistry;
use Trilobit\Core\Presentation\Content\ContentGroup;
use Trilobit\Core\Presentation\Content\ContentGroupRegistry;
use Trilobit\Core\Presentation\Form\FormElementGroup;
use Trilobit\Core\Presentation\Form\FormElementRegistry;

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
 * A group that mirrors a register is not written out here but derived from
 * it, one page per entry: a new component or group of native elements then has
 * a page, a place in the menu and a tile on the front page the moment it is
 * registered, and the only thing left to write is the file that shows it -
 * which the gates insist on.
 *
 * Layout is not here yet, on purpose: a group with nothing to show would be a
 * heading in the menu leading nowhere, and it waits for
 * .ai/plans/09-chrome-a-sirka-obsahu.md.
 *
 * tests/Template/StyleguidePagesTest holds this list and the files under
 * directory() together in both directions.
 */
final class StyleguidePages
{
    /** @var non-empty-list<StyleguideGroup>|null */
    private ?array $groups = null;

    public function __construct(
        private readonly ContentGroupRegistry $contentGroups,
        private readonly FormElementRegistry $formElements,
        private readonly ComponentRegistry $components,
    ) {}

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
                    new StyleguidePage(
                        'foundations',
                        'colours',
                        'Colours',
                        'Every colour token, drawn as the colour it produces in the theme and the mode that are on.',
                    ),
                    new StyleguidePage(
                        'foundations',
                        'content-width',
                        'Content width',
                        'How wide the content runs: the reader chooses, and a page may insist where it has to.',
                    ),
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
            new StyleguideGroup(
                'content',
                'Content',
                'The elements a browser hands us before any class is written, one page for every group of them.',
                array_map(
                    static fn(ContentGroup $group): StyleguidePage => new StyleguidePage(
                        'content',
                        $group->name,
                        ucfirst($group->name),
                        $group->summary,
                        contentGroups: [$group->name],
                    ),
                    $this->contentGroups->all(),
                ),
            ),
            new StyleguideGroup(
                'forms',
                'Forms',
                'The controls of a form as the browser hands them over, drawn out of the theme, one page for '
                . 'every group of them.',
                array_map(
                    static fn(FormElementGroup $group): StyleguidePage => new StyleguidePage(
                        'forms',
                        $group->name,
                        ucfirst($group->name),
                        $group->summary,
                        formElements: [$group->name],
                    ),
                    $this->formElements->all(),
                ),
            ),
            new StyleguideGroup(
                'components',
                'Components',
                'Everything the application is assembled out of, one page for every component.',
                array_map(
                    static fn(Component $component): StyleguidePage => new StyleguidePage(
                        'components',
                        substr($component->name, strlen(ComponentRegistry::PREFIX)),
                        $component->name,
                        $component->summary,
                        components: [$component->name],
                    ),
                    $this->components->all(),
                ),
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
