<?php

declare(strict_types=1);

namespace Trilobit\Core\Media;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Throwable;
use Trilobit\Core\Domain\Media\MediaFile;
use Trilobit\Core\Tenancy\Tenancy;

/**
 * The pictures a business has uploaded, as the rest of the application uses
 * them: a module holds a MediaFile and asks here for the addresses to draw it
 * with.
 *
 * An upload is the files and the row, and it is both or neither. The files are
 * written first by MediaStorage, which takes back what it wrote when a file
 * fails; the row is written after, and when it fails the files are taken back
 * here. Whose the picture is, is settled before either - an upload outside a
 * business writes nothing at all.
 *
 * Removing a picture from what shows it is the module's business and removes
 * only that binding. Deleting the files is for .ai/plans/16-soft-delete.md:
 * until there is a bin to take things back out of, a picture is kept.
 */
final readonly class MediaLibrary
{
    /** What the public directory is called in an address, relative to the site's base path. */
    private const string PUBLIC_PREFIX = 'media';

    /** The length of MediaFile's column for the name the file came with. */
    private const int NAME_LENGTH = 255;

    public function __construct(
        private MediaStorage $storage,
        private EntityManagerInterface $entityManager,
        private Tenancy $tenancy,
    ) {}

    /**
     * Takes in the picture at $file for the business this request is inside.
     *
     * $originalName is what the file was called on the uploader's machine. It
     * is kept to be shown, cut to the column's length, and never used to name
     * anything on disk.
     *
     * @throws UploadRefused when the file is not a picture the library takes
     * @throws MediaNotStored when it is, and it could not be kept
     */
    public function upload(string $file, string $originalName, string $alt = ''): MediaFile
    {
        $tenant = $this->tenancy->tenant();
        $stored = $this->storage->store($file);

        try {
            $media = new MediaFile(
                $tenant,
                $stored->path,
                mb_substr($originalName, 0, self::NAME_LENGTH),
                $stored->type->mime(),
                $stored->bytes,
                new DateTimeImmutable(),
                $stored->width,
                $stored->height,
                $alt,
            );
            $this->entityManager->persist($media);
            $this->entityManager->flush();
        } catch (Throwable $failure) {
            $this->storage->discard($stored->path);

            throw $failure;
        }

        return $media;
    }

    /** The address of $variant of $file, relative to the site's base path. */
    public function url(MediaFile $file, Variant $variant): string
    {
        return self::PUBLIC_PREFIX . '/' . MediaStorage::variantPath($file->path(), $variant);
    }

    /**
     * Every variant of $file with its width, as the srcset attribute of an
     * <img> wants it.
     *
     * A picture smaller than a variant has that variant at its own size, so
     * two variants can be as wide as each other; each width is offered once,
     * the smallest file first.
     */
    public function srcset(MediaFile $file, string $basePath = ''): string
    {
        $width = $file->width();
        $height = $file->height();
        if ($width === null || $height === null) {
            throw new LogicException(sprintf('%s is not a picture, so it has no variants.', $file->path()));
        }

        $candidates = [];
        foreach (Variant::cases() as $variant) {
            [$variantWidth] = $variant->fit($width, $height);
            $candidates[$variantWidth] ??= sprintf('%s/%s %dw', $basePath, $this->url($file, $variant), $variantWidth);
        }

        return implode(', ', $candidates);
    }
}
