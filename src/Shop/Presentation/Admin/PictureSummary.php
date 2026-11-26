<?php

declare(strict_types=1);

namespace Trilobit\Shop\Presentation\Admin;

use Trilobit\Core\Media\MediaLibrary;
use Trilobit\Core\Media\Variant;
use Trilobit\Shop\Domain\Product\ProductImage;

/**
 * One picture of a product as the administration draws it: from the published
 * variants - the smallest as `src`, all of them in `srcset` - and never from
 * the original, which is not served.
 *
 * Worked out while the page is prepared, because an address comes from the
 * media library and a template asking it would be a template holding a
 * service.
 */
final readonly class PictureSummary
{
    public function __construct(
        public int $id,
        /** The smallest variant, from the site's base path. */
        public string $src,
        /** Every variant with its width; see MediaLibrary::srcset(). */
        public string $srcset,
        /** The size of the smallest variant, so that the page does not move when it arrives. */
        public int $width,
        public int $height,
        /** What it shows, for somebody who cannot see it; '' for decoration. */
        public string $alt,
        /** What the file was called on the machine it came from. */
        public string $name,
    ) {}

    public static function of(ProductImage $picture, MediaLibrary $library, string $basePath): self
    {
        $file = $picture->file();
        [$width, $height] = Variant::Thumb->fit($file->width() ?? 1, $file->height() ?? 1);
        $base = rtrim($basePath, '/');

        return new self(
            (int) $picture->id(),
            $base . '/' . $library->url($file, Variant::Thumb),
            $library->srcset($file, $base),
            $width,
            $height,
            $file->alt(),
            $file->originalName(),
        );
    }

    /** The name of the button taking it off, as ProductPresenter registers it. */
    public function remover(): string
    {
        return 'removePicture-' . $this->id;
    }

    /** Its width when its longer side is $side: what the list draws it at. */
    public function widthWithin(int $side): int
    {
        return max(1, (int) round($this->width * $side / max($this->width, $this->height, 1)));
    }

    public function heightWithin(int $side): int
    {
        return max(1, (int) round($this->height * $side / max($this->width, $this->height, 1)));
    }
}
