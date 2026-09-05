<?php

declare(strict_types=1);

namespace Trilobit\Core\Content;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Trilobit\Core\Contract\Content\ContentRef;
use Trilobit\Core\Domain\Content\ContentPath;

/**
 * The register of public addresses, from both sides: what answers where, and
 * what a module is allowed to claim.
 *
 * Every refusal in here happens while somebody is saving. That is the whole
 * design: an address settled at read time would be settled by the order the
 * modules happen to be registered in, and switching one of them off would
 * change it. So a collision, a reserved beginning and an address too long for
 * the index are all answered with Trilobit\Core\Content\PathRefused and a
 * sentence whoever is saving can act on.
 *
 * Renaming is where the shape of the table earns itself. Moving a category
 * rewrites the address of everything under it, and every address that
 * disappears is left behind as a permanent redirect - otherwise every rename
 * quietly breaks every link from outside, and the application looks perfectly
 * healthy while it happens. Because addresses are unique, the rewriting is
 * done in three passes with a flush between them: stale redirects standing on
 * the target addresses are removed, then the branch is renamed, and only then
 * are the old addresses recreated as redirects. In one pass the database would
 * be asked to hold two rows at the same address for the length of a
 * transaction, and would refuse.
 */
final readonly class PathRegistry implements PathLookup
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReservedSegments $reserved,
    ) {}

    public function find(string $path): ?Address
    {
        $row = $this->rowAt($path);

        return $row instanceof ContentPath ? $this->addressOf($row) : null;
    }

    /**
     * Claims $path for $ref.
     *
     * The first address a piece of content gets is its canonical one, because
     * something has to be; every later one is an ordinary address until a
     * person says otherwise through makeCanonical(). That is the difference
     * R12 turns on - a permalink moves when somebody decides it should, not
     * when a product is filed into another category.
     */
    public function register(ContentRef $ref, string $path, string $label, ?string $parentPath = null): Address
    {
        $this->refuseUnusableAddress($path);

        $standing = $this->rowAt($path);
        if ($standing instanceof ContentPath && !$standing->movedTo() instanceof ContentPath) {
            throw PathRefused::alreadyTaken($path);
        }

        if ($standing instanceof ContentPath) {
            // A redirect left behind by an earlier rename. A live address
            // outranks one, and it has to be gone before the new row can be
            // written, because the two would share a unique index.
            $this->entityManager->remove($standing);
            $this->entityManager->flush();
        }

        $parent = null;
        if ($parentPath !== null) {
            $parent = $this->rowAt($parentPath) ?? throw PathRefused::noSuchParent($path, $parentPath);
        }

        $row = new ContentPath($path, $ref->type, $ref->id, $label, $parent);
        if (!$this->canonicalRowOf($ref) instanceof ContentPath) {
            $row->makeCanonical();
        }

        $this->entityManager->persist($row);
        $this->entityManager->flush();

        return $this->addressOf($row);
    }

    /** Which of the addresses of $ref is the permalink; a decision, never a side effect of one. */
    public function makeCanonical(ContentRef $ref, string $path): void
    {
        $row = $this->rowAt($path) ?? throw PathRefused::notRegistered($path);
        $standing = $this->canonicalRowOf($ref);
        if ($standing === $row) {
            return;
        }

        $standing?->makeSecondary();
        $this->entityManager->flush();

        $row->makeCanonical();
        $this->entityManager->flush();
    }

    /**
     * Moves an address, and everything filed under it, leaving each old
     * address behind as a permanent redirect to where it went.
     *
     * The moved branch is re-filed under whatever answers above its new
     * address, so that renaming a category and moving it are one operation
     * rather than two that have to agree.
     */
    public function rename(string $from, string $to): void
    {
        $root = $this->rowAt($from) ?? throw PathRefused::notRegistered($from);
        if ($root->movedTo() instanceof ContentPath) {
            throw PathRefused::notRegistered($from);
        }

        $this->refuseUnusableAddress($to);

        $branch = $this->branchFrom($root);
        $renamed = [];
        foreach ($branch as $row) {
            $was = $row->path();
            $becomes = $to . substr($was, strlen($from));
            if (strlen($becomes) > PublicPath::MAX_LENGTH) {
                throw PathRefused::tooLong($becomes);
            }

            $standing = $this->rowAt($becomes);
            if ($standing instanceof ContentPath && !$standing->movedTo() instanceof ContentPath) {
                throw PathRefused::alreadyTaken($becomes);
            }

            if ($standing instanceof ContentPath) {
                $this->entityManager->remove($standing);
            }

            $renamed[$was] = $row;
        }

        // Pass one: the target addresses are free before anything moves onto
        // them, because a delete is the last thing a flush does and would
        // otherwise happen after the update that needs the room.
        $this->entityManager->flush();

        foreach ($renamed as $was => $row) {
            $row->rename($to . substr($was, strlen($from)));
        }

        $parentPath = PublicPath::parentOf($to);
        $root->fileUnder($parentPath === null ? null : $this->rowAt($parentPath));
        $this->entityManager->flush();

        foreach ($renamed as $was => $row) {
            $trail = new ContentPath($was, $row->type(), $row->contentId(), $row->label());
            $trail->moveTo($row);
            $this->entityManager->persist($trail);
        }

        $this->entityManager->flush();
    }

    /**
     * Gives up an address without leaving a redirect - what filing a product
     * out of a category does, as opposed to renaming the category.
     *
     * The canonical address is held on to while any other address of the same
     * content is registered, so that a permalink is never moved by whatever
     * happens to be removed next.
     */
    public function forget(string $path): void
    {
        $row = $this->rowAt($path) ?? throw PathRefused::notRegistered($path);
        $ref = new ContentRef($row->type(), $row->contentId());

        if ($row->isCanonical() && count($this->addressesOf($ref)) > 1) {
            throw PathRefused::stillTheCanonicalAddress($path);
        }

        $this->entityManager->remove($row);
        $this->entityManager->flush();
    }

    /**
     * Every live address of one piece of content, canonical first.
     *
     * A sitemap takes the canonical one and nothing else; a page takes the one
     * the visitor arrived at. Redirects are left out - they are addresses that
     * used to answer.
     *
     * @return list<Address>
     */
    public function addressesOf(ContentRef $ref): array
    {
        $addresses = [];
        foreach ($this->rows()->findBy(['type' => $ref->type, 'contentId' => $ref->id, 'movedTo' => null], ['path' => 'ASC']) as $row) {
            $addresses[] = $this->addressOf($row);
        }

        usort($addresses, static function (Address $a, Address $b): int {
            if ($a->isCanonical() !== $b->isCanonical()) {
                return $a->isCanonical() ? -1 : 1;
            }

            return $a->path <=> $b->path;
        });

        return $addresses;
    }

    private function refuseUnusableAddress(string $path): void
    {
        if (!PublicPath::isCanonical($path)) {
            throw PathRefused::notCanonical($path);
        }

        if (strlen($path) > PublicPath::MAX_LENGTH) {
            throw PathRefused::tooLong($path);
        }

        $first = PublicPath::firstSegment($path);
        if ($this->reserved->isReserved($first)) {
            throw PathRefused::reservedSegment($path, $first);
        }
    }

    /**
     * $row and everything filed under it, a parent always before its children.
     *
     * Redirects are never in it: an address that no longer answers is not
     * somewhere anything can be filed under, and ContentPath::moveTo() takes
     * it out of the tree for that reason.
     *
     * @return list<ContentPath>
     */
    private function branchFrom(ContentPath $row): array
    {
        $branch = [$row];
        foreach ($this->rows()->findBy(['parent' => $row, 'movedTo' => null], ['path' => 'ASC']) as $child) {
            $branch = [...$branch, ...$this->branchFrom($child)];
        }

        return $branch;
    }

    private function addressOf(ContentPath $row): Address
    {
        $ref = new ContentRef($row->type(), $row->contentId());

        return new Address(
            $row->path(),
            $ref,
            $row->label(),
            $this->canonicalRowOf($ref)?->path() ?? $row->path(),
            $row->parent()?->path(),
            $row->movedTo()?->path(),
        );
    }

    private function canonicalRowOf(ContentRef $ref): ?ContentPath
    {
        return $this->rows()->findOneBy(['canonicalOf' => ContentPath::canonicalKey($ref->type, $ref->id)]);
    }

    private function rowAt(string $path): ?ContentPath
    {
        return $this->rows()->findOneBy(['path' => $path]);
    }

    /** @return EntityRepository<ContentPath> */
    private function rows(): EntityRepository
    {
        return $this->entityManager->getRepository(ContentPath::class);
    }
}
