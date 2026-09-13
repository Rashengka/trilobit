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

    /**
     * The last part of an address that is not one - said differently for a
     * slash and for a dot, because each of them is refused for a reason of its
     * own and the person typing can only act on the one that applies.
     */
    public static function notASegment(string $segment): self
    {
        if ($segment === '') {
            return new self('The last part of an address cannot be empty.');
        }

        if (str_contains($segment, '/')) {
            return new self(sprintf(
                "'%s' is more than one part of an address. Only the last part is written here; what it is filed "
                . 'under is chosen from the categories, so that everything above it is an address that exists.',
                $segment,
            ));
        }

        $normalized = PublicPath::normalize($segment);
        $instead = $normalized === '' ? 'Nothing in it can be kept.' : sprintf("'%s' says the same thing.", $normalized);

        if (str_contains($segment, '.')) {
            return new self(sprintf(
                "'%s' has a dot in it, and no address here may: Nette's routing reads a dot as the boundary between "
                . 'two module names. An extension such as .html is not part of an address either - an old address '
                . 'with one is carried over as a redirect instead. %s',
                $segment,
                $instead,
            ));
        }

        return new self(sprintf(
            "'%s' cannot be the last part of an address as it is written. An address is lower case letters of the "
            . 'English alphabet, digits and single hyphens between them. %s',
            $segment,
            $instead,
        ));
    }

    public static function stillHasChildren(string $path, int $count): self
    {
        return new self(sprintf(
            "'%s' still has %d %s filed under it. Move %s somewhere else or delete %s first - deleting it now "
            . 'would take %s with it.',
            $path,
            $count,
            $count === 1 ? 'address' : 'addresses',
            $count === 1 ? 'it' : 'them',
            $count === 1 ? 'it' : 'them',
            $count === 1 ? 'it' : 'them',
        ));
    }

    public static function intoItself(string $from, string $to): self
    {
        return new self(sprintf("'%s' cannot be moved to '%s', which is inside it.", $from, $to));
    }

    public static function noSuchCategory(string $id): self
    {
        return new self(sprintf(
            "There is no category '%s' to file this under; it may have been deleted in the meantime.",
            $id,
        ));
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
