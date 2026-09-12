<?php

declare(strict_types=1);

namespace Trilobit\Core\Content;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Trilobit\Core\Contract\Content\ContentRef;
use Trilobit\Core\Domain\Content\ContentPath;
use Trilobit\Core\Tenancy\Tenancy;

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
 *
 * The register is one address space per tenant. Reading is scoped by
 * Trilobit\Core\Tenancy\TenantFilter, so nothing in here restates the tenant
 * in a criterion - a query that had to remember would be a query the next
 * person forgets - and writing takes it from Trilobit\Core\Tenancy\Tenancy,
 * because a filter is not consulted on an insert. Two businesses therefore
 * both have a page at /kontakt and neither can reach the other's.
 */
final readonly class PathRegistry implements PathLookup
{
    /**
     * The language every row is written and read in until T13b gives the
     * register more than one.
     *
     * The column is in the unique index from the day the index exists, so that
     * the index is migrated once; nothing chooses between languages yet, and a
     * constant is the honest way to say that. **Exit condition:** the moment a
     * caller may say which language it means.
     */
    public const string LANGUAGE = 'en';

    /**
     * How many numbered variants of a title suggest() tries before giving up.
     * A hundred pages of one name under one category is somebody who needs a
     * different name, not a hundred and first number.
     */
    private const int SUGGESTION_ATTEMPTS = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReservedSegments $reserved,
        private Tenancy $tenancy,
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
        $refusal = $this->refusalOf($path, null);
        if ($refusal instanceof PathRefused) {
            throw $refusal;
        }

        $standing = $this->rowAt($path);
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

        $row = new ContentPath($this->tenancy->tenant(), self::LANGUAGE, $path, $ref->type, $ref->id, $label, $parent);
        if (!$this->canonicalRowOf($ref) instanceof ContentPath) {
            $row->makeCanonical();
        }

        $this->entityManager->persist($row);
        $this->entityManager->flush();

        return $this->addressOf($row);
    }

    /**
     * The address $segment would have filed under $parentPath, or a refusal
     * when $segment is not one part of an address (decision C2).
     *
     * A form asks for a category and a last part, never for a whole address,
     * because a deeper address typed by hand is a row whose parents do not
     * exist; this is where the two halves are put back together, so that
     * every caller refuses the same things in the same words.
     */
    public function addressUnder(?string $parentPath, string $segment): string
    {
        if (!PublicPath::isSegment($segment)) {
            throw PathRefused::notASegment($segment);
        }

        return PublicPath::join($parentPath, $segment);
    }

    /**
     * A last part for something called $text, filed under $parentPath, that
     * saving would accept - or '' when $text holds nothing to make one of.
     *
     * **It is asked here and not worked out in the browser**, because the
     * answer is only worth having if it is the same answer saving gives: the
     * candidates go through refusalOf(), the very check register() makes, so a
     * reserved beginning, an address too long for the index and one somebody
     * else holds are skipped by construction rather than by a second copy of
     * the rules that would drift from the first. An address $for already
     * holds is not taken from it, so asking again for a saved page suggests
     * the address it has.
     *
     * A taken candidate is followed by the same one with -2, -3 and so on,
     * cut short where the whole address would outgrow the index.
     */
    public function suggest(string $text, ?string $parentPath = null, ?ContentRef $for = null): string
    {
        $base = PublicPath::segmentOf($text);
        $room = PublicPath::MAX_LENGTH - ($parentPath === null ? 0 : strlen($parentPath) + 1);
        if ($base === '' || $room < 1) {
            return '';
        }

        for ($attempt = 1; $attempt <= self::SUGGESTION_ATTEMPTS; ++$attempt) {
            $suffix = $attempt === 1 ? '' : '-' . $attempt;
            $segment = rtrim(substr($base, 0, max(0, $room - strlen($suffix))), '-') . $suffix;
            if (!PublicPath::isSegment($segment)) {
                continue;
            }

            if (!$this->refusalOf(PublicPath::join($parentPath, $segment), $for) instanceof PathRefused) {
                return $segment;
            }
        }

        return '';
    }

    /**
     * Every live address of every content of $type, by address.
     *
     * @return list<Address>
     */
    public function addressesOfType(string $type): array
    {
        return array_map(
            $this->addressOf(...),
            $this->rows()->findBy(['type' => $type, 'movedTo' => null], ['path' => 'ASC']),
        );
    }

    public function canonicalPathOf(ContentRef $ref): ?string
    {
        return $this->canonicalRowOf($ref)?->path();
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
     * Says what the register is to call $ref from now on, at every address it
     * answers at.
     *
     * The label is the register's own copy of a title, kept so that drawing a
     * trail across two modules' content costs no call into a module that may
     * not be in this build. A copy has to be told when the original changes,
     * or a renamed page keeps its old name in every breadcrumb and every menu
     * above it - while looking perfectly right on the page itself, which is
     * why nobody notices.
     */
    public function describe(ContentRef $ref, string $label): void
    {
        foreach ($this->rows()->findBy(['type' => $ref->type, 'contentId' => $ref->id]) as $row) {
            $row->describeAs($label);
        }

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

        $unusable = $this->unusable($to);
        if ($unusable instanceof PathRefused) {
            throw $unusable;
        }

        if (str_starts_with($to, $from . '/')) {
            // Its own branch would be renamed under itself and then filed
            // under one of its own descendants: a loop, not a tree.
            throw PathRefused::intoItself($from, $to);
        }

        // The same refusal register() makes. Without it the branch would be
        // filed under nothing at an address that says it is under something -
        // the row without parents categories exist to prevent.
        $parentPath = PublicPath::parentOf($to);
        $parent = $parentPath === null ? null : $this->rowAt($parentPath);
        if ($parentPath !== null && (!$parent instanceof ContentPath || $parent->movedTo() instanceof ContentPath)) {
            throw PathRefused::noSuchParent($to, $parentPath);
        }

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

        $root->fileUnder($parent);
        $this->entityManager->flush();

        foreach ($renamed as $was => $row) {
            $trail = new ContentPath($row->tenant(), $row->language(), $was, $row->type(), $row->contentId(), $row->label());
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

        // The database would take everything filed under it along - the
        // foreign key cascades - and Doctrine would not know it had. Deleting
        // a category must never delete a page, so it is refused instead, and
        // where the pages should go is asked of a person (plan 16).
        $children = $this->rows()->count(['parent' => $row]);
        if ($children > 0) {
            throw PathRefused::stillHasChildren($path, $children);
        }

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

    /**
     * Why $path cannot be claimed by $for, or null when it can.
     *
     * One question for register() and for suggest() alike, so that a
     * suggestion is by construction something saving accepts. An address
     * $for already holds is its own and not taken; one left behind as a
     * redirect is free, because a live address outranks one.
     */
    private function refusalOf(string $path, ?ContentRef $for): ?PathRefused
    {
        $unusable = $this->unusable($path);
        if ($unusable instanceof PathRefused) {
            return $unusable;
        }

        $standing = $this->rowAt($path);
        if (!$standing instanceof ContentPath || $standing->movedTo() instanceof ContentPath) {
            return null;
        }

        $holder = new ContentRef($standing->type(), $standing->contentId());

        return $for instanceof ContentRef && $holder->equals($for) ? null : PathRefused::alreadyTaken($path);
    }

    /** Whatever makes $path an address nobody may hold, whoever asks: its shape, its length, its beginning. */
    private function unusable(string $path): ?PathRefused
    {
        if (!PublicPath::isCanonical($path)) {
            return PathRefused::notCanonical($path);
        }

        if (strlen($path) > PublicPath::MAX_LENGTH) {
            return PathRefused::tooLong($path);
        }

        $first = PublicPath::firstSegment($path);

        return $this->reserved->isReserved($first) ? PathRefused::reservedSegment($path, $first) : null;
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
