<?php

declare(strict_types=1);

namespace Trilobit\Core\Media;

/**
 * A picture MediaStorage has kept: the original and every variant are on disk.
 *
 * There is no partly stored picture to describe - storing either returns this
 * or raises, having removed whatever it wrote - so this carries no flag for
 * which of the files made it.
 */
final readonly class StoredPicture
{
    public function __construct(
        /** The stored name, relative to both media directories: `ab/abcdef….jpg`. */
        public string $path,
        public ImageType $type,
        /** As the picture is meant to be seen, which for a photo taken on its side is not how it was stored. */
        public int $width,
        public int $height,
        /** The size of the original. */
        public int $bytes,
    ) {}
}
