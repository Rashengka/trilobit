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
 * finding that something answers there.
 *
 * Turning a title into a segment is a different job, and segmentOf() does it:
 * there a letter does become the letter it is built on, because a title is
 * written in the site's own language and dropping every accented letter out
 * of it would leave a suggestion nobody wants. It asks ext-intl where that is
 * loaded and iconv where it is not, and neither is a new dependency - iconv is
 * compiled into the image. What comes out is only ever a suggestion: it goes
 * through the same refusals as anything typed by hand before it is stored.
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

    /**
     * Whether $segment is one part of an address in the stored shape - what a
     * form may hold as the last part, under whatever category was chosen.
     *
     * A slash is two parts, typed by hand, and a deeper address typed by hand
     * is a row whose parents do not exist. A dot is refused with everything
     * else outside a-z, 0-9 and the hyphen, and it is the one worth naming:
     * Nette's routing reads it as the boundary between two module names.
     */
    public static function isSegment(string $segment): bool
    {
        return !str_contains($segment, '/') && self::isCanonical($segment);
    }

    public static function join(?string $parent, string $segment): string
    {
        return $parent === null ? $segment : $parent . '/' . $segment;
    }

    /**
     * $text as one segment: letters folded onto the ones they are built on,
     * everything else a hyphen, a slash included. Empty when nothing in it
     * could be kept.
     */
    public static function segmentOf(string $text): string
    {
        return self::normalize(str_replace('/', ' ', self::toAscii($text)));
    }

    private static function toAscii(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');

        if (class_exists(\Transliterator::class)) {
            $ascii = \Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($text);
            if (is_string($ascii)) {
                return $ascii;
            }
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return is_string($ascii) ? $ascii : $text;
    }
}
