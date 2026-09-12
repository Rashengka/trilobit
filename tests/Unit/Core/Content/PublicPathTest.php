<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Content\PublicPath;

#[CoversClass(PublicPath::class)]
final class PublicPathTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function spellings(): iterable
    {
        yield 'already canonical' => ['bikes/mountain', 'bikes/mountain'];
        yield 'upper case' => ['Bikes/Mountain', 'bikes/mountain'];
        yield 'a trailing slash' => ['bikes/mountain/', 'bikes/mountain'];
        yield 'a leading slash' => ['/bikes/mountain', 'bikes/mountain'];
        yield 'a doubled slash' => ['bikes//mountain', 'bikes/mountain'];
        // Written as an escape rather than as the letter itself, so that this
        // file stays plain ASCII and the leak guard's rule about non-English
        // letters keeps biting everywhere else. A letter outside the English
        // alphabet is dropped rather than folded onto the letter it is built
        // on; see the class docblock for why, and for when that has to change.
        yield 'a letter outside the English alphabet' => ["caf\u{00e9}", 'caf'];
        yield 'spaces' => ['mountain bike x', 'mountain-bike-x'];
        yield 'the root' => ['/', ''];
    }

    #[DataProvider('spellings')]
    public function testEverySpellingNormalisesToTheOneStoredForm(string $written, string $stored): void
    {
        self::assertSame($stored, PublicPath::normalize($written));
    }

    #[DataProvider('spellings')]
    public function testOnlyTheStoredFormIsCanonical(string $written, string $stored): void
    {
        self::assertSame($written === $stored && $stored !== '', PublicPath::isCanonical($written));
    }

    /**
     * The root is a static route rather than a row in the register, so no
     * address in it is ever the empty string - which is also what stops a
     * blank slug from claiming the homepage.
     */
    public function testTheEmptyAddressIsNotCanonical(): void
    {
        self::assertFalse(PublicPath::isCanonical(''));
    }

    public function testTheFirstSegmentIsWhatStandsBeforeTheFirstSlash(): void
    {
        self::assertSame('bikes', PublicPath::firstSegment('bikes/mountain/mountain-bike-x'));
        self::assertSame('about', PublicPath::firstSegment('about'));
        self::assertSame('', PublicPath::firstSegment(''));
    }

    public function testTheParentIsEverythingButTheLastSegment(): void
    {
        self::assertSame('bikes/mountain', PublicPath::parentOf('bikes/mountain/mountain-bike-x'));
        self::assertSame('bikes', PublicPath::parentOf('bikes/mountain'));
        self::assertNull(PublicPath::parentOf('bikes'));
    }

    /**
     * What a form may hold as the last part of an address: one segment, in
     * the stored shape. A slash would be a second segment typed by hand, which
     * is exactly the row without parents that categories exist to prevent; a
     * dot is where Nette's routing reads the boundary between two module
     * names, and an extension such as .html is the commonest shape of one.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function lastParts(): iterable
    {
        yield 'a segment' => ['first-ride', true];
        yield 'digits' => ['2026', true];
        yield 'a slash' => ['guides/first-ride', false];
        yield 'an extension' => ['first-ride.html', false];
        yield 'a dot on its own' => ['first.ride', false];
        yield 'a trailing dot' => ['first-ride.', false];
        yield 'upper case' => ['First-Ride', false];
        yield 'a space' => ['first ride', false];
        yield 'nothing' => ['', false];
    }

    #[DataProvider('lastParts')]
    public function testOnlyOneSegmentInTheStoredShapeIsASegment(string $written, bool $isSegment): void
    {
        self::assertSame($isSegment, PublicPath::isSegment($written));
    }

    /**
     * Titles are written in whatever language the site is in, so a letter
     * with a diacritic becomes the letter it is built on here - unlike
     * normalize(), which only has to recognise another spelling of an address
     * that already exists. Escapes rather than the letters themselves, for
     * the reason given in spellings().
     *
     * @return iterable<string, array{string, string}>
     */
    public static function titles(): iterable
    {
        yield 'plain words' => ['First ride', 'first-ride'];
        yield 'punctuation' => ['Mountain bikes & co.', 'mountain-bikes-co'];
        yield 'an extension' => ['index.html', 'index-html'];
        yield 'a slash' => ['Guides / Winter', 'guides-winter'];
        yield 'diacritics' => ["P\u{0159}\u{00ed}li\u{0161} \u{017e}lu\u{0165}ou\u{010d}k\u{00fd} k\u{016f}\u{0148}", 'prilis-zlutoucky-kun'];
        yield 'nothing worth keeping' => ['!!!', ''];
    }

    #[DataProvider('titles')]
    public function testATitleBecomesOneSegment(string $title, string $segment): void
    {
        self::assertSame($segment, PublicPath::segmentOf($title));
    }

    public function testASegmentIsJoinedUnderItsParent(): void
    {
        self::assertSame('guides/first-ride', PublicPath::join('guides', 'first-ride'));
        self::assertSame('first-ride', PublicPath::join(null, 'first-ride'));
    }
}
