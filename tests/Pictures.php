<?php

declare(strict_types=1);

namespace Trilobit\Tests;

use GdImage;
use PHPUnit\Framework\Assert;

/**
 * Pictures made for a test, byte by byte, rather than photographs kept in the
 * repository.
 *
 * Every one of them is drawn here: four coloured quarters, so that which way up
 * a picture came out can be read off its corners after it has been scaled and
 * compressed. The EXIF block a phone writes is written here too, by hand, with
 * a turn and an invented position in it - which is the pair of things the media
 * library has to treat differently: the turn it has to apply, and the position
 * it must not publish.
 */
final class Pictures
{
    public const string RED = 'red';

    public const string GREEN = 'green';

    public const string BLUE = 'blue';

    public const string YELLOW = 'yellow';

    /** @var array<string, array{int, int, int}> */
    private const array COLOURS = [
        self::RED => [220, 30, 30],
        self::GREEN => [30, 200, 30],
        self::BLUE => [30, 30, 220],
        self::YELLOW => [230, 220, 30],
    ];

    /**
     * A picture whose quarters are red, green, blue and yellow, reading from
     * the top left like a page.
     */
    public static function quarters(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        $halfWidth = intdiv($width, 2);
        $halfHeight = intdiv($height, 2);

        self::fill($image, 0, 0, $halfWidth - 1, $halfHeight - 1, self::RED);
        self::fill($image, $halfWidth, 0, $width - 1, $halfHeight - 1, self::GREEN);
        self::fill($image, 0, $halfHeight, $halfWidth - 1, $height - 1, self::BLUE);
        self::fill($image, $halfWidth, $halfHeight, $width - 1, $height - 1, self::YELLOW);

        return $image;
    }

    public static function jpeg(int $width, int $height): string
    {
        ob_start();
        imagejpeg(self::quarters($width, $height), null, 95);

        return self::captured();
    }

    public static function png(int $width, int $height): string
    {
        ob_start();
        imagepng(self::quarters($width, $height));

        return self::captured();
    }

    /** A PNG with a transparent left half, to show a variant keeps what is see-through. */
    public static function transparentPng(int $width, int $height): string
    {
        $image = self::quarters($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
        Assert::assertIsInt($clear);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, $clear);

        ob_start();
        imagepng($image);

        return self::captured();
    }

    public static function webp(int $width, int $height): string
    {
        ob_start();
        imagewebp(self::quarters($width, $height), null, 90);

        return self::captured();
    }

    public static function gif(int $width, int $height): string
    {
        ob_start();
        imagegif(self::quarters($width, $height));

        return self::captured();
    }

    /**
     * $jpeg with the EXIF block a phone writes: which way up it was held, and
     * where it was taken.
     *
     * The position is invented - a point at sea - and the numbers are only
     * there so that their absence from a published file can be asserted.
     */
    public static function withExif(string $jpeg, int $orientation): string
    {
        Assert::assertStringStartsWith("\xFF\xD8", $jpeg, 'EXIF goes into a JPEG, right after its start marker');

        $entry = static fn(int $tag, int $type, int $count, string $value): string
            => pack('nnN', $tag, $type, $count) . str_pad($value, 4, "\0");

        $ifd0Offset = 8;
        $gpsOffset = $ifd0Offset + 2 + 2 * 12 + 4;
        $dataOffset = $gpsOffset + 2 + 4 * 12 + 4;

        $ifd0 = pack('n', 2)
            . $entry(0x0112, 3, 1, pack('n', $orientation))
            . $entry(0x8825, 4, 1, pack('N', $gpsOffset))
            . pack('N', 0);

        $gps = pack('n', 4)
            . $entry(0x0001, 2, 2, "N\0")
            . $entry(0x0002, 5, 3, pack('N', $dataOffset))
            . $entry(0x0003, 2, 2, "E\0")
            . $entry(0x0004, 5, 3, pack('N', $dataOffset + 24))
            . pack('N', 0);

        $rationals = pack('N*', 12, 1, 34, 1, 56, 1, 65, 1, 43, 1, 21, 1);

        $tiff = 'MM' . pack('nN', 0x2A, $ifd0Offset) . $ifd0 . $gps . $rationals;
        $payload = "Exif\0\0" . $tiff;

        return "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    /**
     * The first bytes of a PNG that says it is $width by $height, and nothing
     * behind them worth decoding.
     *
     * It is what a decompression bomb looks like to anything that reads only
     * the header: a few dozen bytes on disk, and gigabytes the moment somebody
     * decodes it.
     */
    public static function pngClaiming(int $width, int $height): string
    {
        $chunk = static fn(string $type, string $data): string
            => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
            . $chunk('IEND', '');
    }

    /**
     * Which of the four colours the pixel at ($x, $y) is nearest to - after a
     * JPEG has been scaled twice, exact values are not what survives.
     */
    public static function colourAt(GdImage $image, int $x, int $y): string
    {
        $rgb = imagecolorat($image, $x, $y);
        Assert::assertIsInt($rgb);
        $red = ($rgb >> 16) & 0xFF;
        $green = ($rgb >> 8) & 0xFF;
        $blue = $rgb & 0xFF;

        $nearest = '';
        $distance = PHP_INT_MAX;
        foreach (self::COLOURS as $name => [$r, $g, $b]) {
            $candidate = ($red - $r) * ($red - $r) + ($green - $g) * ($green - $g) + ($blue - $b) * ($blue - $b);
            if ($candidate < $distance) {
                $nearest = $name;
                $distance = $candidate;
            }
        }

        return $nearest;
    }

    /**
     * The colours in the middle of each quarter of $image, reading from the
     * top left like a page.
     *
     * @return list<string>
     */
    public static function quartersOf(GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $left = intdiv($width, 4);
        $right = intdiv($width * 3, 4);
        $top = intdiv($height, 4);
        $bottom = intdiv($height * 3, 4);

        return [
            self::colourAt($image, $left, $top),
            self::colourAt($image, $right, $top),
            self::colourAt($image, $left, $bottom),
            self::colourAt($image, $right, $bottom),
        ];
    }

    public static function open(string $file): GdImage
    {
        $bytes = file_get_contents($file);
        Assert::assertIsString($bytes, 'the file should be there: ' . $file);
        $image = imagecreatefromstring($bytes);
        Assert::assertInstanceOf(GdImage::class, $image, 'the file should be a picture: ' . $file);

        return $image;
    }

    private static function fill(GdImage $image, int $x1, int $y1, int $x2, int $y2, string $colour): void
    {
        [$red, $green, $blue] = self::COLOURS[$colour];
        $allocated = imagecolorallocate($image, $red, $green, $blue);
        Assert::assertIsInt($allocated);
        imagefilledrectangle($image, $x1, $y1, $x2, $y2, $allocated);
    }

    private static function captured(): string
    {
        $bytes = ob_get_clean();
        Assert::assertIsString($bytes);

        return $bytes;
    }
}
