<?php

declare(strict_types=1);

namespace Trilobit\Cms\Application\Page;

use DateTimeImmutable;
use LogicException;
use Trilobit\Cms\Domain\Page\Page;
use Trilobit\Cms\Domain\Page\PageRepository;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Content\PathRefused;
use Trilobit\Core\Content\PathRegistry;
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
 * Every refusal comes back as Trilobit\Core\Content\PathRefused with a
 * sentence for whoever is typing - the address is taken, it begins with
 * something reserved, it is spelled in a way addresses are not stored in - and
 * nothing is left behind when one happens: a page whose address was refused is
 * removed again, because a page nothing can reach is not a draft, it is
 * litter.
 */
final readonly class Pages
{
    public function __construct(
        private PageRepository $pages,
        private PathRegistry $addresses,
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
     * A new page at $address, in draft.
     *
     * The address is claimed straight away, before anybody may see the page,
     * so that writing a page and holding on to where it will live are one act.
     */
    public function create(string $title, string $address): Page
    {
        $page = new Page($this->tenancy->tenant(), $title, $this->now());
        $this->pages->save($page);

        try {
            $this->addresses->register($this->refOf($page), $address, $title);
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
     * Moves the page to $address, leaving the old one behind as a permanent
     * redirect - which is the register's doing and the reason a page is not
     * free to change where it lives without saying so.
     */
    public function moveTo(Page $page, string $address): void
    {
        $standing = $this->addressOf($page);
        if ($standing === $address) {
            return;
        }

        if ($standing === null) {
            $this->addresses->register($this->refOf($page), $address, $page->title());

            return;
        }

        $this->addresses->rename($standing, $address);
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
