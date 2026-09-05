<?php

declare(strict_types=1);

namespace Trilobit\Core\Content;

use Trilobit\Core\Domain\Content\ContentPath;

/**
 * The one shape a public address is allowed to be stored in, and the way any
 * other shape is turned into it.
 *
 * There is one address per piece of content in one spelling: lower case, the
 * English alphabet and digits, single hyphens where anything else stood,
 * segments separated by a single slash, no slash at either end. Every other
 * spelling of the same address - a capital letter, a trailing slash, a doubled
 * slash - is answered with a permanent redirect to this one rather than
 * served, because two spellings that both answer are two pages to a search
 * engine, to a cache and to whoever pasted the link.
 *
 * Normalising is lossy on purpose, and it is deliberately not clever: a letter
 * outside the English alphabet becomes a hyphen rather than the letter it is
 * built on, because folding one to the other needs a transliterator and this
 * project carries no INTL extension - see the note in compose.override.yaml
 * about which extensions the image installs. That costs nothing here, where
 * the question is only which of several spellings of an existing address a
 * visitor typed, and the router redirects to a normalised address only after
 * finding that something answers there. It will cost something the day a
 * module turns a title into a slug for the first time, and that is the day to
 * decide whether the application depends on ext-intl.
 */
final class PublicPath
{
    public const int MAX_LENGTH = ContentPath::MAX_PATH_LENGTH;

    /** The register never holds an empty address; the root is a static route, not content. */
    public static function isCanonical(string $path): bool
    {
        return $path !== '' && $path === self::normalize($path);
    }

    public static function normalize(string $path): string
    {
        $segments = [];
        foreach (explode('/', strtolower($path)) as $segment) {
            // Byte-wise rather than /u, so that a request carrying broken
            // UTF-8 comes back as an address nobody claims instead of making
            // the matcher return null and the whole router give up.
            $segment = trim((string) preg_replace('#[^a-z0-9]+#', '-', $segment), '-');
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    /** @return list<string> */
    public static function segments(string $path): array
    {
        return $path === '' ? [] : explode('/', $path);
    }

    /**
     * The segment the whole address space is carved up by: what stands before
     * the first slash. A static route and a piece of content can never share
     * one, which is what Trilobit\Core\Content\ReservedSegments is for.
     */
    public static function firstSegment(string $path): string
    {
        return self::segments($path)[0] ?? '';
    }

    /** Everything but the last segment, or null for an address at the root. */
    public static function parentOf(string $path): ?string
    {
        $segments = self::segments($path);
        array_pop($segments);

        return $segments === [] ? null : implode('/', $segments);
    }
}
