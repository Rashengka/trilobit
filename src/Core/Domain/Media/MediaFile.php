<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\Media;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Domain\Tenancy\Tenant;

/**
 * A file somebody uploaded, as the application refers to it.
 *
 * It is the one entity of Core a module may point a foreign key at
 * (.ai/plans/01c-datovy-model.md), and it can be because Core cannot be
 * switched off: a picture belongs to a product and to a page at the same time,
 * and neither module may know about the other.
 *
 * The row is the record, not the file. The bytes live under the public
 * directory at `path`, and the path is unique because two rows describing one
 * file would let deleting either of them break the other.
 *
 * Width and height are optional because not everything a shop uploads is a
 * picture: a price list is a file with a size and no dimensions.
 *
 * Media belongs to a tenant and is never shared, so every read of this table
 * is scoped by Trilobit\Core\Tenancy\TenantFilter. The path stays unique
 * across the whole installation all the same: it names a file on disk, and two
 * rows naming one file is the hazard above whichever tenants they belong to.
 * What a tenant is kept from seeing is the row, which is what the filter does.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_media_file')]
class MediaFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        /** Whose file this is. Media is never shared between two businesses. */
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        /** Relative to the public directory, so that moving an installation does not rewrite every row. */
        #[ORM\Column(length: 255, unique: true)]
        private string $path,
        #[ORM\Column(length: 255)]
        private string $originalName,
        #[ORM\Column(length: 127)]
        private string $mime,
        #[ORM\Column]
        private int $sizeBytes,
        #[ORM\Column]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(nullable: true)]
        private ?int $width = null,
        #[ORM\Column(nullable: true)]
        private ?int $height = null,
        /** What a screen reader says instead of showing it; empty means the picture is decoration. */
        #[ORM\Column(length: 255)]
        private string $alt = '',
    ) {}

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function mime(): string
    {
        return $this->mime;
    }

    public function sizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function width(): ?int
    {
        return $this->width;
    }

    public function height(): ?int
    {
        return $this->height;
    }

    public function alt(): string
    {
        return $this->alt;
    }

    public function describeAs(string $alt): void
    {
        $this->alt = $alt;
    }
}
