<?php

declare(strict_types=1);

namespace Trilobit\Cms\Seed;

use Trilobit\Cms\Application\Page\Pages;
use Trilobit\Cms\Domain\Menu\MenuItem;
use Trilobit\Cms\Domain\Menu\MenuRepository;
use Trilobit\Cms\Domain\Page\Page;
use Trilobit\Core\Content\Categories;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Seed\SeedProvider;

/**
 * The pages and the menu `app:seed` gives every business it makes.
 *
 * What is here is what clicking through the site and its administration needs
 * to meet: a page at the root of the site, pages filed under a category so
 * that an address is deeper than one part, a draft whose address a visitor is
 * told is not there, and a main menu with an entry holding two others under
 * it - the arrangement the menu screens have to show and the site's
 * navigation has to draw. One entry leads out of the site, so that all the
 * kinds an entry can be are there but the one naming another module, which
 * this module has no business naming.
 *
 * The first page carries the business's name and sits at the same address in
 * both, which is the claim tenancy makes put where a person can see it: two
 * businesses, one address, and each shows its own.
 *
 * Everything goes through the services the administration writes with - see
 * Trilobit\Core\Seed\SeedProvider for why that is the rule.
 */
final readonly class CmsSeed implements SeedProvider
{
    public function __construct(
        private Pages $pages,
        private Categories $categories,
        private MenuRepository $menus,
    ) {}

    public function seed(Tenant $business): array
    {
        $name = $business->name();

        $about = $this->published(
            'About ' . $name,
            'about',
            sprintf('%s is a business somebody made up so that there is something to click through.', $name),
            "Nothing on this page is true.\nIt was written by the seed, and it is the same address in every business the seed makes - each one shows its own.",
        );
        $care = $this->published(
            'Customer care',
            'customer-care',
            'Where the help pages are listed.',
            'The menu holds the pages under this one; the address of each is under the Help category.',
        );

        $help = $this->categories->create('Help', 'help', null);
        $delivery = $this->published('Delivery', 'delivery', 'How an order would arrive, if there were any.', 'Filed under the Help category, so it answers one level down.', $help->ref->id);
        $returns = $this->published('Returns', 'returns', 'How an order would go back.', 'Filed under the Help category as well.', $help->ref->id);

        $draft = $this->pages->create('Summer sale', 'summer-sale');
        $this->pages->revise(
            $draft,
            'Summer sale',
            'A page that is still being written.',
            'Its address is claimed, and a visitor opening it is told nothing is there.',
            '',
            '',
        );

        $this->menus->save(MenuItem::toPage($business, MenuItem::MAIN, 'About us', $about, 0));

        $careEntry = MenuItem::toPage($business, MenuItem::MAIN, 'Customer care', $care, 1);
        $this->menus->save($careEntry);
        foreach ([$delivery, $returns] as $position => $page) {
            $entry = MenuItem::toPage($business, MenuItem::MAIN, $page->title(), $page, $position);
            $entry->fileUnder($careEntry);
            $this->menus->save($entry);
        }

        $this->menus->save(MenuItem::toUrl($business, MenuItem::MAIN, 'Elsewhere', 'https://www.example.org/', 2));

        return [
            sprintf(
                'four published pages - %s, %s, %s and %s',
                $this->where($about),
                $this->where($care),
                $this->where($delivery),
                $this->where($returns),
            ),
            sprintf('a draft, %s, which a visitor is told is not there', $this->where($draft)),
            'the category Help, which Delivery and Returns are filed under',
            'the main menu: About us, Customer care with Delivery and Returns under it, and Elsewhere, leading out of the site',
        ];
    }

    private function published(string $title, string $segment, string $perex, string $body, ?string $category = null): Page
    {
        $page = $this->pages->create($title, $segment, $category);
        $this->pages->revise($page, $title, $perex, $body, '', '');
        $this->pages->publish($page);

        return $page;
    }

    /** The page's title and the address it answers at, the way the report names a page. */
    private function where(Page $page): string
    {
        return sprintf('%s at /%s', $page->title(), $this->pages->addressOf($page) ?? '');
    }
}
