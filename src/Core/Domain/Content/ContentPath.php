<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\Content;

use Doctrine\ORM\Mapping as ORM;

/**
 * One public address, and what answers at it.
 *
 * This is the register the whole public address space is read out of: a
 * request arrives with a path, this table says which content that path names,
 * and only then is a presenter chosen. No module's name and no presenter's
 * name ever appears in a URL, so the two trees - the one modules are nested
 * in and the one addresses are nested in - are free of each other.
 *
 * Three things about the shape are load-bearing.
 *
 * **The whole path is in the row, not one segment of it.** Reading is the hot
 * path and has to cost one exact lookup over a unique index whatever the
 * depth; resolving segment by segment would make every request pay for a
 * nesting decision somebody makes once a month. The limit that follows is on
 * the length of a path rather than on how deep it may go - a depth limit would
 * be an invented number, while the length is what the index can carry.
 *
 * **`parent` is kept beside the path, so the tree stays a tree.** The path
 * answers "what is here"; the parent answers "what is this under", which is
 * what breadcrumbs, menus and renaming a whole branch need.
 *
 * **A content may hold several rows.** A product sits in as many categories as
 * it belongs to and is reachable at one address per category, all answering
 * 200 rather than redirecting - a visitor keeps the context the link was given
 * in. One of them carries `canonicalOf` and is the address search engines and
 * the sitemap are told about; the unique index on that column is what makes
 * "exactly one canonical address per content" a fact of the database instead
 * of a rule somebody remembers.
 *
 * A row whose `movedTo` is filled in is an address that used to be live and
 * now answers 301. It is kept out of the tree - `parent` is null on it - so
 * that walking the tree only ever meets addresses that answer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_content_path')]
class ContentPath
{
    /** What the unique index over a utf8mb4 column can carry, and therefore the longest address there can be. */
    public const int MAX_PATH_LENGTH = 255;

    public const int MAX_TYPE_LENGTH = 64;

    public const int MAX_CONTENT_ID_LENGTH = 64;

    public const int MAX_LABEL_LENGTH = 191;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The address this row is the canonical one of, as `type:id`, or null when
     * it is one of the other addresses of the same content.
     *
     * It restates two columns that are already here, and it earns that: a
     * unique index over it is the only way this server can express "at most
     * one canonical row per content". A partial index would say it more
     * directly and MariaDB has none.
     */
    #[ORM\Column(length: 191, unique: true, nullable: true)]
    private ?string $canonicalOf = null;

    /** Where this address moved to, or null while it is live. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?self $movedTo = null;

    public function __construct(
        #[ORM\Column(length: self::MAX_PATH_LENGTH, unique: true)]
        private string $path,
        /** Which kind of content this is, namespaced by the module that owns it: `blog.article`. */
        #[ORM\Column(length: self::MAX_TYPE_LENGTH)]
        private string $type,
        /** The owning module's own identifier, kept as a string because the module that reads it is not the one that minted it. */
        #[ORM\Column(length: self::MAX_CONTENT_ID_LENGTH)]
        private string $contentId,
        /** What a breadcrumb or a menu calls this, so that drawing a trail costs no call into another module. */
        #[ORM\Column(length: self::MAX_LABEL_LENGTH)]
        private string $label,
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(onDelete: 'CASCADE')]
        private ?self $parent = null,
    ) {}

    public function id(): ?int
    {
        return $this->id;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function contentId(): string
    {
        return $this->contentId;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function parent(): ?self
    {
        return $this->parent;
    }

    public function movedTo(): ?self
    {
        return $this->movedTo;
    }

    public function isCanonical(): bool
    {
        return $this->canonicalOf !== null;
    }

    /** The value the unique index carries for $type and $contentId. */
    public static function canonicalKey(string $type, string $contentId): string
    {
        return $type . ':' . $contentId;
    }

    public function makeCanonical(): void
    {
        $this->canonicalOf = self::canonicalKey($this->type, $this->contentId);
    }

    public function makeSecondary(): void
    {
        $this->canonicalOf = null;
    }

    public function rename(string $path): void
    {
        $this->path = $path;
    }

    public function describeAs(string $label): void
    {
        $this->label = $label;
    }

    /**
     * Turns this address into a permanent redirect to another one.
     *
     * It leaves the tree at the same moment, because an address that no longer
     * answers is not somewhere anything can be under.
     */
    public function moveTo(self $target): void
    {
        $this->movedTo = $target;
        $this->parent = null;
        $this->canonicalOf = null;
    }
}
