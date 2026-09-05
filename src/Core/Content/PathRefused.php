<?php

declare(strict_types=1);

namespace Trilobit\Core\Content;

/**
 * A public address the register would not take, and the reason in a sentence.
 *
 * Every refusal happens while somebody is saving, never while somebody is
 * reading. An address decided at read time - whoever is found first wins -
 * would be decided by the order modules happen to be registered in, and that
 * order changes when one of them is switched off.
 *
 * The messages are written to be shown to whoever typed the address, because
 * that is who can fix it. "That address is taken" is a sentence an editor can
 * act on; a constraint violation from the database is not.
 */
final class PathRefused extends \RuntimeException
{
    public static function notCanonical(string $path): self
    {
        return new self(sprintf(
            "'%s' is not the shape an address is stored in. Addresses are lower case, without diacritics, "
            . "with single slashes between the segments and none at either end - '%s' says the same thing.",
            $path,
            PublicPath::normalize($path),
        ));
    }

    public static function tooLong(string $path): self
    {
        return new self(sprintf(
            "'%s' is %d characters long and an address may be at most %d, which is what the unique index over "
            . 'them can carry. There is no limit on how deeply content may be nested, only on how long the '
            . 'whole address is.',
            $path,
            strlen($path),
            PublicPath::MAX_LENGTH,
        ));
    }

    public static function reservedSegment(string $path, string $segment): self
    {
        return new self(sprintf(
            "'%s' cannot start with '%s', because something else already answers there and content saved under "
            . 'it would never be reachable. Reserved beginnings are the administration, the style guide and the '
            . 'name of every module this installation declares.',
            $path,
            $segment,
        ));
    }

    public static function alreadyTaken(string $path): self
    {
        return new self(sprintf("'%s' is already the address of something else.", $path));
    }

    public static function noSuchParent(string $path, string $parent): self
    {
        return new self(sprintf(
            "'%s' was to be filed under '%s', and no address answers there.",
            $path,
            $parent,
        ));
    }

    public static function notRegistered(string $path): self
    {
        return new self(sprintf("No content is registered at '%s'.", $path));
    }

    public static function stillTheCanonicalAddress(string $path): self
    {
        return new self(sprintf(
            "'%s' is the canonical address of its content and other addresses of the same content are still "
            . 'registered. Name one of those canonical first, so that the permalink is moved on purpose rather '
            . 'than by whatever is removed next.',
            $path,
        ));
    }
}
