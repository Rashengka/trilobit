<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Component;

use Trilobit\Core\Domain\Media\MediaFile;
use Trilobit\Core\Media\MediaLibrary;
use Trilobit\Core\Media\Variant;

/**
 * One picture of a c-lightbox: the small one it is chosen by, the large one it
 * opens as over the page, and the file at the largest size there is.
 *
 * The addresses are resolved before the template is drawn, the way a crumb's
 * are, because they come from the media library and a template asking it
 * would be a template holding a service. A picture of the library is made by
 * ofMedia(); anything else - the style guide's drawings - is written out.
 */
final readonly class LightboxPicture
{
    public function __construct(
        /** The caller's name for it, unique within the lightbox: what the ids of its parts are made of. */
        public string $key,
        /** The small picture it is chosen by. */
        public string $thumbSrc,
        public int $thumbWidth,
        public int $thumbHeight,
        /** The picture it opens as, and its size, so that the page does not move when it arrives. */
        public string $src,
        public int $width,
        public int $height,
        /** What it shows, for somebody who cannot see it; '' for decoration. */
        public string $alt,
        /** What is written over it when it is open; '' says only where it is among the others. */
        public string $caption,
        /** The picture at the largest size anybody is given. */
        public string $fullSrc,
        /** Every size there is of it with its width, for both pictures to choose from; '' when there is one. */
        public string $srcset = '',
    ) {}

    /**
     * $file of the media library: chosen by its smallest variant and opened as
     * its largest, which is also the largest size anybody is given - the
     * original is never served.
     */
    public static function ofMedia(MediaFile $file, MediaLibrary $library, string $basePath, string $key, string $caption): self
    {
        $base = rtrim($basePath, '/');
        $width = $file->width() ?? 1;
        $height = $file->height() ?? 1;
        [$thumbWidth, $thumbHeight] = Variant::Thumb->fit($width, $height);
        [$largeWidth, $largeHeight] = Variant::Large->fit($width, $height);
        $large = $base . '/' . $library->url($file, Variant::Large);

        return new self(
            $key,
            $base . '/' . $library->url($file, Variant::Thumb),
            $thumbWidth,
            $thumbHeight,
            $large,
            $largeWidth,
            $largeHeight,
            $file->alt(),
            $caption,
            $large,
            $library->srcset($file, $base),
        );
    }
}
