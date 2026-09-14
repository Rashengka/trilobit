<?php

declare(strict_types=1);

namespace Trilobit\Core\Media;

use Closure;
use GdImage;
use Nette\IOException;
use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use Throwable;

/**
 * Where the bytes of a picture are kept, and what is done to them on the way.
 *
 * Two directories with one rule between them. The original is copied whole
 * into a directory outside the public one (var/media), because a phone writes
 * where a photo was taken into it and that is nobody else's business. What the
 * site shows are the variants (see Variant) in the public directory
 * (www/media), made by decoding the original and encoding it again - which is
 * what leaves its metadata behind, since gd writes none.
 *
 * What kind of picture a file is, is read from its content, and only JPEG, PNG
 * and WebP are taken (see ImageType). The name it is stored under is random and
 * the extension is the detected type's, so nothing the uploader chose ends up
 * in a path - and nothing ending in .php can be made to exist in the public
 * directory through here.
 *
 * Two limits are checked before a picture is decoded, both on what the file
 * says about itself. MAX_BYTES bounds the upload. MAX_PIXELS bounds the memory:
 * a picture is decoded whole, about four bytes a pixel, and a PNG of a few
 * kilobytes can declare a size that would need gigabytes. The header is read
 * with getimagesize(), which does not decode.
 *
 * Storing is all or nothing. It either returns a StoredPicture with the
 * original and every variant on disk, or raises - UploadRefused for the file's
 * fault, MediaNotStored for the server's - having removed whatever it wrote.
 */
final readonly class MediaStorage
{
    /** 20 MiB: a phone photo is a few, a PNG of a photo can be ten times that. */
    public const int MAX_BYTES = 20 * 1024 * 1024;

    /**
     * 4096 by 4096, about 16.8 million: more than a 12- or 16-megapixel phone
     * photo, and 64 MiB decoded. Measured on PHP 8.5: storing a JPEG of exactly
     * this size peaked at 95.8 MB, under PHP's stock memory_limit of 128M. A
     * 48-megapixel photo in full resolution would not fit, and is refused with
     * that said.
     */
    public const int MAX_PIXELS = 4096 * 4096;

    private const int JPEG_QUALITY = 82;

    private const int WEBP_QUALITY = 80;

    private const int PNG_COMPRESSION = 6;

    /** The EXIF orientations that turn a picture on its side, so that width and height change places. */
    private const array SIDEWAYS = [5, 6, 7, 8];

    /** @var Closure(): string */
    private Closure $names;

    /**
     * @param (Closure(): string)|null $names where stored names come from: 32
     *     hexadecimal characters, random unless a test needs to know the name
     *     in advance
     */
    public function __construct(
        /** Where originals are kept; not served. */
        private string $originals,
        /** Where variants are published; served as they are. */
        private string $public,
        ?Closure $names = null,
    ) {
        $this->names = $names ?? static fn(): string => bin2hex(random_bytes(16));
    }

    /** Where $variant of the picture stored as $path is, relative to the public directory. */
    public static function variantPath(string $path, Variant $variant): string
    {
        $dot = strrpos($path, '.');
        if ($dot === false) {
            return $path . '-' . $variant->value;
        }

        return substr($path, 0, $dot) . '-' . $variant->value . substr($path, $dot);
    }

    /**
     * Keeps the picture in $file: the original, and every variant made from
     * it.
     *
     * @throws UploadRefused when the file is not a picture that is taken
     * @throws MediaNotStored when it is, and it could not be kept
     */
    public function store(string $file): StoredPicture
    {
        [$type, $bytes] = $this->inspect($file);
        $image = $this->decode($file, $type) ?? throw UploadRefused::notAPicture();
        $orientation = $this->orientationOf($file, $type);

        $name = ($this->names)();
        $path = substr($name, 0, 2) . '/' . $name . '.' . $type->extension();

        $original = $this->originals . '/' . $path;
        foreach ([$original, ...$this->variantFiles($path)] as $taken) {
            if (file_exists($taken)) {
                throw MediaNotStored::nameTaken($taken);
            }
        }

        $written = [];

        try {
            $written[] = $original;
            $this->copy($file, $original);
            [$width, $height] = $this->writeVariants($image, $type, $orientation, $path, $written);
        } catch (Throwable $failure) {
            foreach ($written as $partial) {
                FileSystem::delete($partial);
            }

            throw $failure;
        }

        return new StoredPicture($path, $type, $width, $height, $bytes);
    }

    /**
     * Makes every variant of the picture stored as $path again, from its
     * original, over the ones there are.
     *
     * Each variant is replaced in one step, so a page served meanwhile gets
     * the old file or the new one and never half of either.
     *
     * @throws MediaNotStored
     */
    public function regenerate(string $path): void
    {
        $original = $this->originals . '/' . $path;
        if (!is_file($original)) {
            throw MediaNotStored::noOriginal($original);
        }

        try {
            [$type] = $this->inspect($original);
        } catch (UploadRefused $refused) {
            throw MediaNotStored::notAPicture($original, $refused);
        }

        $image = $this->decode($original, $type) ?? throw MediaNotStored::couldNotDecode($original);
        $written = [];
        $this->writeVariants($image, $type, $this->orientationOf($original, $type), $path, $written);
    }

    /**
     * Every picture kept, by its stored name.
     *
     * @return list<string>
     */
    public function originals(): array
    {
        if (!is_dir($this->originals)) {
            return [];
        }

        $paths = [];
        foreach (Finder::findFiles('*')->from($this->originals) as $file) {
            $paths[] = strtr(substr($file->getPathname(), strlen($this->originals) + 1), '\\', '/');
        }

        sort($paths);

        return $paths;
    }

    /** Removes the original of $path and every variant of it; what is not there already is left alone. */
    public function discard(string $path): void
    {
        foreach ([$this->originals . '/' . $path, ...$this->variantFiles($path)] as $file) {
            FileSystem::delete($file);
        }
    }

    /**
     * The type and the size of $file, from its size and its header alone.
     *
     * @return array{ImageType, int}
     */
    private function inspect(string $file): array
    {
        $bytes = filesize($file);
        if ($bytes === false) {
            throw MediaNotStored::noOriginal($file);
        }

        if ($bytes > self::MAX_BYTES) {
            throw UploadRefused::tooLarge($bytes, self::MAX_BYTES);
        }

        // Reads the header and nothing else. Silenced because a truncated file
        // makes it raise a notice as well as return false, and the false is the
        // whole of the answer.
        $detected = $bytes === 0 ? false : @getimagesize($file);
        if ($detected === false) {
            throw UploadRefused::notAPicture();
        }

        $type = ImageType::fromDetected($detected[2]) ?? throw UploadRefused::notAPicture();
        [$width, $height] = $detected;

        if ($width < 1 || $height < 1) {
            throw UploadRefused::notAPicture();
        }

        if ($width * $height > self::MAX_PIXELS) {
            throw UploadRefused::tooManyPixels($width, $height, self::MAX_PIXELS);
        }

        return [$type, $bytes];
    }

    private function decode(string $file, ImageType $type): ?GdImage
    {
        // Silenced for the same reason as getimagesize(): a damaged file makes
        // the decoder warn and return false, and false is what is looked at.
        $image = match ($type) {
            ImageType::Jpeg => @imagecreatefromjpeg($file),
            ImageType::Png => @imagecreatefrompng($file),
            ImageType::Webp => @imagecreatefromwebp($file),
        };

        if (!$image instanceof GdImage) {
            return null;
        }

        // A PNG with a palette decodes to one, and scaling into a palette
        // mangles colours; its transparent entry becomes real transparency.
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    /**
     * Which way up the photo was taken, as its EXIF says; 1 is upright.
     *
     * Only a JPEG is asked, because exif reads EXIF out of JPEG and TIFF only,
     * and the other two formats are rarely what a camera writes.
     */
    private function orientationOf(string $file, ImageType $type): int
    {
        if ($type !== ImageType::Jpeg) {
            return 1;
        }

        // Silenced because cameras write EXIF that exif finds fault with - a
        // maker's note of an unexpected length - and warns about while still
        // reading the rest. A photo is not refused over that.
        $exif = @exif_read_data($file);
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return is_int($orientation) && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /**
     * Writes every variant of $image, adding each file to $written as soon as
     * it exists.
     *
     * Each variant is scaled first and turned afterwards, so the turn - which
     * copies the whole picture - is done on the small one and not on the
     * original.
     *
     * @param list<string> $written
     * @return array{int, int} the width and height the picture is seen at
     */
    private function writeVariants(GdImage $image, ImageType $type, int $orientation, string $path, array &$written): array
    {
        $sideways = in_array($orientation, self::SIDEWAYS, true);
        [$width, $height] = $sideways ? [imagesy($image), imagesx($image)] : [imagesx($image), imagesy($image)];

        foreach (Variant::cases() as $variant) {
            [$variantWidth, $variantHeight] = $variant->fit($width, $height);
            $scaled = $sideways
                ? $this->scaled($image, $variantHeight, $variantWidth)
                : $this->scaled($image, $variantWidth, $variantHeight);

            $target = $this->public . '/' . self::variantPath($path, $variant);
            $this->write($this->turned($scaled, $orientation, $target), $type, $target);
            $written[] = $target;
        }

        return [$width, $height];
    }

    private function scaled(GdImage $image, int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));

        // What is see-through in a PNG or a WebP stays see-through: the canvas
        // starts transparent, and the copy writes alpha rather than blending it
        // into black.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        if ($clear !== false) {
            imagefill($canvas, 0, 0, $clear);
        }

        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $canvas;
    }

    /**
     * $image turned the way EXIF orientation $orientation says.
     *
     * imagerotate() turns anticlockwise, so a quarter to the right is -90.
     * The mirrored ones are a flip followed by that same quarter turn.
     */
    private function turned(GdImage $image, int $orientation, string $target): GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 2 || $orientation === 7 ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 6, 7 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $turned = imagerotate($image, $angle, 0);
        if ($turned === false) {
            throw MediaNotStored::couldNotEncode($target);
        }

        imagealphablending($turned, false);
        imagesavealpha($turned, true);

        return $turned;
    }

    private function write(GdImage $image, ImageType $type, string $target): void
    {
        ob_start();

        try {
            $encoded = match ($type) {
                ImageType::Jpeg => imagejpeg($image, null, self::JPEG_QUALITY),
                ImageType::Png => imagepng($image, null, self::PNG_COMPRESSION),
                ImageType::Webp => imagewebp($image, null, self::WEBP_QUALITY),
            };
        } finally {
            $bytes = ob_get_clean();
        }

        if (!$encoded || !is_string($bytes) || $bytes === '') {
            throw MediaNotStored::couldNotEncode($target);
        }

        try {
            FileSystem::createDir(dirname($target));
            FileSystem::writeAtomic($target, $bytes);
        } catch (IOException $failure) {
            throw MediaNotStored::couldNotWrite($target, $failure);
        }
    }

    private function copy(string $file, string $target): void
    {
        try {
            FileSystem::copy($file, $target, overwrite: false);
        } catch (IOException $failure) {
            throw MediaNotStored::couldNotWrite($target, $failure);
        }
    }

    /** @return list<string> */
    private function variantFiles(string $path): array
    {
        return array_map(
            fn(Variant $variant): string => $this->public . '/' . self::variantPath($path, $variant),
            Variant::cases(),
        );
    }
}
