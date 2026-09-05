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
}
