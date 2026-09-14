<?php

declare(strict_types=1);

namespace Trilobit\Core\Media;

/**
 * The kinds of picture the media library takes, and nothing else.
 *
 * Three raster formats every browser draws. SVG is not one of them on purpose:
 * it is a document that can carry script, so a picture somebody uploads could
 * run in the page of whoever looks at it. GIF is left out because nothing a
 * shop shows needs it, and every format taken is one more decoder facing files
 * from strangers.
 *
 * Which of them a file is, is read from its content - see MediaStorage - never
 * from its name or from what the browser claims it is. Both of those are
 * written by the person uploading.
 */
enum ImageType: string
{
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';

    /** The type getimagesize() recognised, or null for one the library does not take. */
    public static function fromDetected(int $detected): ?self
    {
        return match ($detected) {
            IMAGETYPE_JPEG => self::Jpeg,
            IMAGETYPE_PNG => self::Png,
            IMAGETYPE_WEBP => self::Webp,
            default => null,
        };
    }

    public function mime(): string
    {
        return $this->value;
    }

    /** What a stored file of this type is named with; the name before it is random. */
    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Png => 'png',
            self::Webp => 'webp',
        };
    }
}
