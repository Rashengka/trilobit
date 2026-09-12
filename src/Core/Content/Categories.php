<?php

declare(strict_types=1);

namespace Trilobit\Core\Content;

use Nette\Utils\Random;
use Trilobit\Core\Contract\Content\ContentRef;

/**
 * Categories: addresses in the register that other addresses are filed under.
 *
 * **A category is a row of the register and nothing else.** There is no table
 * of categories and no entity: the register already holds a path, a parent and
 * a label for every address (decisions R7 and R11), and that is the whole of
 * what a category is. It is told apart from a page or a product by its type,
 * and its identifier is minted here, because there is no module whose row it
 * would otherwise be the identifier of.
 *
 * **It is Core's, and shared, on purpose** (.ai/plans/11-cms-po-prvnim-proklikani.md,
 * C4): pages are filed into categories and so will products be, and the
 * address space they share is Core's already. That is also the exit
 * condition for keeping it here. The day a category wants anything of its own
 * - a picture, a description, fields for search engines - it has become
 * content, and it moves out into a module of its own while Core keeps the
 * tree.
 *
 * **Nothing here decides an address.** Joining a last part under a category,
 * refusing one, moving a branch and leaving redirects behind are all the
 * register's, and a category is renamed by the very call a page is moved with
 * - so that there is one way addresses move and not two that have to agree.
 *
 * A category address answers 404 to a visitor for now: nothing draws a list of
 * what is in one - that waits for the first module with a catalogue to list -
 * and an address whose type nothing draws is simply not routed.
 */
final readonly class Categories
{
    /** What the register calls a category, namespaced by Core because Core owns it. */
    public const string TYPE = 'core.category';

    /** Long enough that two categories of one business never draw the same one. */
    private const int IDENTIFIER_LENGTH = 16;

    public function __construct(
        private PathRegistry $addresses,
    ) {}

    /** @return list<Address> every category there is, by address */
    public function all(): array
    {
        return $this->addresses->addressesOfType(self::TYPE);
    }

    public function find(string $id): ?Address
    {
        $path = $this->addresses->canonicalPathOf($this->refOf($id));

        return $path === null ? null : $this->addresses->find($path);
    }

    /**
     * Where the category $id is, or null for no category at all.
     *
     * An identifier naming no category is refused rather than read as the
     * root: somebody chose a category, and filing their page at the root of
     * the site instead would be doing something they did not ask for.
     */
    public function pathOf(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return $this->find($id)->path ?? throw PathRefused::noSuchCategory($id);
    }

    public function create(string $name, string $segment, ?string $parent): Address
    {
        $parentPath = $this->pathOf($parent);
        $path = $this->addresses->addressUnder($parentPath, $segment);

        return $this->addresses->register(
            $this->refOf(Random::generate(self::IDENTIFIER_LENGTH, '0-9a-z')),
            $path,
            $name,
            $parentPath,
        );
    }

    /**
     * Renames and moves a category in one go, and everything filed under it
     * with it - through PathRegistry::rename(), which leaves every address
     * that disappears behind as a permanent redirect (decision R4).
     */
    public function revise(string $id, string $name, string $segment, ?string $parent): void
    {
        $standing = $this->pathOf($id) ?? throw PathRefused::noSuchCategory($id);
        $target = $this->addresses->addressUnder($this->pathOf($parent), $segment);

        if ($target !== $standing) {
            $this->addresses->rename($standing, $target);
        }

        $this->addresses->describe($this->refOf($id), $name);
    }

    /**
     * Deletes a category that holds nothing.
     *
     * One that holds something is refused by the register, loudly: where its
     * pages should go instead is a question for the person deleting it, and
     * the form that asks it belongs to .ai/plans/16-soft-delete.md.
     */
    public function delete(string $id): void
    {
        $this->addresses->forget($this->pathOf($id) ?? throw PathRefused::noSuchCategory($id));
    }

    /**
     * $path taken apart into a category and a last part, or null when the
     * part above the last one is not a category - an address typed out whole
     * before categories existed, which no choice of category can say.
     */
    public function placementOf(string $path): ?Placement
    {
        $segments = PublicPath::segments($path);
        $segment = array_pop($segments) ?? '';
        if ($segments === []) {
            return new Placement(null, $segment);
        }

        $parent = $this->addresses->find(implode('/', $segments));
        if (!$parent instanceof Address || $parent->hasMoved() || $parent->ref->type !== self::TYPE) {
            return null;
        }

        return new Placement($parent->ref->id, $segment);
    }

    /** A last part for a category called $name under $parent; see PathRegistry::suggest(). */
    public function suggest(string $name, ?string $parent, ?string $for): string
    {
        return $this->addresses->suggest($name, $this->pathOf($parent), $for === null ? null : $this->refOf($for));
    }

    private function refOf(string $id): ContentRef
    {
        return new ContentRef(self::TYPE, $id);
    }
}
