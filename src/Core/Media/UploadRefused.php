<?php

declare(strict_types=1);

namespace Trilobit\Core\Media;

use RuntimeException;

/**
 * A file was not taken into the media library because of what it is.
 *
 * Nothing has been written when this is raised: every reason is found out from
 * the size and the header, before the picture is decoded. It is a different
 * exception from MediaNotStored because it means a different thing to the
 * person uploading - try another file, rather than try again later.
 */
final class UploadRefused extends RuntimeException
{
    private function __construct(
        public readonly Refusal $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notAPicture(): self
    {
        return new self(
            Refusal::NotAPicture,
            'The file is not a JPEG, PNG or WebP picture. What it is was read from its content; its name and the '
            . 'type the browser sent were not asked.',
        );
    }

    public static function tooLarge(int $bytes, int $limit): self
    {
        return new self(
            Refusal::TooLarge,
            sprintf('The file has %d bytes and at most %d are taken.', $bytes, $limit),
        );
    }

    public static function tooManyPixels(int $width, int $height, int $limit): self
    {
        return new self(
            Refusal::TooManyPixels,
            sprintf(
                'The picture is %d by %d pixels and at most %d pixels in all are taken. A picture is decoded into '
                . 'memory whole, so the limit is on the pixels rather than on the bytes, which can be few.',
                $width,
                $height,
                $limit,
            ),
        );
    }
}
