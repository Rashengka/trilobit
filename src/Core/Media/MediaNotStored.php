<?php

declare(strict_types=1);

namespace Trilobit\Core\Media;

use RuntimeException;
use Throwable;

/**
 * A picture that was acceptable could not be kept, and nothing of it was.
 *
 * Raised on the server's side of an upload - a file that could not be written,
 * a name already taken, an original that is gone. Whatever had been written
 * before the failure has been removed again by the time this is seen, so the
 * one thing an upload never ends in is a row pointing at half a set of files.
 */
final class MediaNotStored extends RuntimeException
{
    public static function couldNotWrite(string $file, Throwable $previous): self
    {
        return new self(sprintf('Could not write %s: %s', $file, $previous->getMessage()), 0, $previous);
    }

    public static function couldNotDecode(string $file): self
    {
        return new self(sprintf('%s passed as a picture by its header, and then could not be decoded.', $file));
    }

    public static function couldNotEncode(string $file): self
    {
        return new self(sprintf('The picture for %s could not be encoded.', $file));
    }

    public static function nameTaken(string $file): self
    {
        return new self(sprintf(
            '%s already exists. Stored names are random, so a second file under one name means the source of '
            . 'names is broken - and writing over the first would replace somebody else\'s picture.',
            $file,
        ));
    }

    public static function noOriginal(string $file): self
    {
        return new self(sprintf('There is no original at %s to make variants from.', $file));
    }

    public static function notAPicture(string $file, UploadRefused $previous): self
    {
        return new self(sprintf('The original at %s is not a picture the library takes.', $file), 0, $previous);
    }
}
