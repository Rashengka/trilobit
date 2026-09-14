<?php

declare(strict_types=1);

namespace Trilobit\Core\Media;

/**
 * Why an upload was turned away, for a form to say in its own words.
 *
 * Each of them is the file's fault rather than the server's, and each is known
 * before anything has been written.
 */
enum Refusal
{
    /** Not a JPEG, PNG or WebP by its content, whatever it was called. */
    case NotAPicture;

    /** More bytes than MediaStorage::MAX_BYTES. */
    case TooLarge;

    /** More pixels than MediaStorage::MAX_PIXELS, which is decided from the header alone. */
    case TooManyPixels;
}
