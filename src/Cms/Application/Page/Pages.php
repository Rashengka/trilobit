<?php

declare(strict_types=1);

namespace Trilobit\Cms\Application\Page;

use DateTimeImmutable;
use LogicException;
use Trilobit\Cms\Domain\Page\Page;
use Trilobit\Cms\Domain\Page\PageRepository;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Content\Categories;
use Trilobit\Core\Content\PathRefused;
use Trilobit\Core\Content\PathRegistry;
use Trilobit\Core\Content\Placement;
use Trilobit\Core\Contract\Content\ContentRef;
use Trilobit\Core\Tenancy\Tenancy;

/**
 * Everything writing a page involves, in the one place that knows a page is
 * two rows.
 *
 * A page is what it says, kept in this module, and where it answers, kept in
 * Core's register of public addresses. Splitting them is decision R1 and it is
 * what lets a page and a product share the root of the site; the cost is that
 * the two have to be written together, and this is where that is done. A
 * presenter calling the repository and the register in turn would be the same
 * knowledge written out again in every place a page can be edited from.
 *
 * **Where a page answers is given as a category and a last part**, never as a
 * whole address (.ai/plans/11-cms-po-prvnim-proklikani.md, C2 and C4). A
 * deeper address typed by hand is a row whose parents do not exist, so the
 * part above the last one is always a category somebody chose, and Core puts
 * the two together - see Trilobit\Core\Content\PathRegistry::addressUnder().
 *
 * Every refusal comes back as Trilobit\Core\Content\PathRefused with a
 * sentence for whoever is typing - the address is taken, it begins with
 * something reserved, the last part holds a slash or a dot - and nothing is
 * left behind when one happens: a page whose address was refused is removed
 * again, because a page nothing can reach is not a draft, it is litter.
 */
final readonly class Pages
{
    public function __construct(
        private PageRepository $pages,
        private PathRegistry $addresses,
        private Categories $categories,
        private Tenancy $tenancy,
    ) {}

    public function find(int $id): ?Page
    {
        return $this->pages->find($id);
    }

    /** @return list<Page> */
    public function all(): array
    {
        return $this->pages->all();
    }

    /** Where $page answers, or null while it has no address at all. */
    public function addressOf(Page $page): ?string
    {
        return $this->addresses->canonicalPathOf($this->refOf($page));
    }

    /**
     * Where $page answers, as the category it is filed under and its last
     * part - or null for an address typed out whole before categories
     * existed, which no category and last part can say.
     */
    public function placementOf(Page $page): ?Placement
    {
        $address = $this->addressOf($page);

        return $address === null ? new Placement(null, '') : $this->categories->placementOf($address);
    }

    /**
     * A new page at $segment, filed under the category $category or at the
     * root of the site, in draft.
     *
     * The address is claimed straight away, before anybody may see the page,
     * so that writing a page and holding on to where it will live are one act.
     * It is worked out before the page is saved, so that a last part refused
     * for its shape leaves nothing behind at all.
     */
    public function create(string $title, string $segment, ?string $category = null): Page
    {
        $parentPath = $this->categories->pathOf($category);
        $address = $this->addresses->addressUnder($parentPath, $segment);

        $page = new Page($this->tenancy->tenant(), $title, $this->now());
        $this->pages->save($page);

        try {
            $this->addresses->register($this->refOf($page), $address, $title, $parentPath);
        } catch (PathRefused $refused) {
            $this->pages->remove($page);

            throw $refused;
        }

        return $page;
    }

    /** What the page says. The register is told the new title, because it keeps a copy for trails and menus. */
    public function revise(
        Page $page,
        string $title,
        string $perex,
        string $content,
        string $seoTitle,
        string $seoDescription,
    ): void {
        $page->reviseTo($title, $perex, $content, $seoTitle, $seoDescription, $this->now());
        $this->pages->save($page);
        $this->addresses->describe($this->refOf($page), $title);
    }

    /**
     * Moves the page to $segment under the category $category, leaving the
     * old address behind as a permanent redirect - which is the register's
     * doing, and the very call a category is renamed with.
     */
    public function moveTo(Page $page, string $segment, ?string $category = null): void
    {
        $parentPath = $this->categories->pathOf($category);
        $address = $this->addresses->addressUnder($parentPath, $segment);

        $standing = $this->addressOf($page);
        if ($standing === $address) {
            return;
        }

        if ($standing === null) {
            $this->addresses->register($this->refOf($page), $address, $page->title(), $parentPath);

            return;
        }

        $this->addresses->rename($standing, $address);
    }

    /**
     * A last part for a page called $title under $category that saving would
     * accept, or '' when the title holds nothing to make one of. The page
     * being edited keeps its own address rather than being told it is taken.
     */
    public function suggestSegment(string $title, ?string $category, ?Page $page): string
    {
        return $this->addresses->suggest($title, $this->categories->pathOf($category), $page?->ref());
    }

    public function publish(Page $page): void
    {
        $page->publish($this->now());
        $this->pages->save($page);
    }

    public function withdraw(Page $page): void
    {
        $page->withdraw($this->now());
        $this->pages->save($page);
    }

    /**
     * Takes the page and every address it answers at.
     *
     * The addresses go first and the permalink goes last: the register holds
     * on to a canonical address while any other address of the same content is
     * still registered, so that a permalink is never moved by whatever happens
     * to be removed next.
     */
    public function delete(Page $page): void
    {
        $ref = $this->refOf($page);
        foreach (array_reverse($this->addresses->addressesOf($ref)) as $address) {
            $this->addresses->forget($address->path);
        }

        $this->pages->remove($page);
    }

    /** @return list<Address> every address this page answers at, the permalink first */
    public function addressesOf(Page $page): array
    {
        return $this->addresses->addressesOf($this->refOf($page));
    }

    /**
     * A page that has never been saved has no identifier, so nothing can point
     * at it. Every caller here saves first, so meeting one is a mistake in
     * this class rather than something a page can be in.
     */
    private function refOf(Page $page): ContentRef
    {
        return $page->ref() ?? throw new LogicException('A page has to be saved before it can have an address.');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
