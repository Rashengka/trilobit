<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Security\Grant;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * How a piece of a role is written down and read back.
 *
 * The format is small and every mistake in it is quiet - a piece that does not
 * read back is dropped, and a dropped piece is a right nobody reports missing -
 * so what is asserted is the round trip, and that nothing but the two shapes
 * reads back at all.
 */
#[CoversClass(Grant::class)]
final class GrantTest extends TestCase
{
    public function testAPairReadsBackAsItWasWrittenOut(): void
    {
        $grant = Grant::parse('content:edit');

        self::assertInstanceOf(Grant::class, $grant);
        self::assertSame(Resource::Content, $grant->resource);
        self::assertSame(Privilege::Edit, $grant->privilege);
        self::assertFalse($grant->isWhole());
        self::assertSame('content:edit', $grant->code());
    }

    /** The star is the whole resource, and it is written back as a star rather than as a list. */
    public function testAStarIsTheWholeOfAResource(): void
    {
        $grant = Grant::parse('administration:*');

        self::assertInstanceOf(Grant::class, $grant);
        self::assertSame(Resource::Administration, $grant->resource);
        self::assertNull($grant->privilege);
        self::assertTrue($grant->isWhole());
        self::assertSame('administration:*', $grant->code());
    }

    public function testTheWholeOfAResourceIsWrittenOutAsAStar(): void
    {
        self::assertSame('content:*', new Grant(Resource::Content, null)->code());
    }

    /** @return iterable<string, array{string}> */
    public static function unreadable(): iterable
    {
        yield 'a resource this build does not have' => ['invoicing:view'];
        yield 'the whole of a resource this build does not have' => ['invoicing:*'];
        yield 'a privilege this build does not have' => ['content:apostille'];
        yield 'no privilege at all' => ['content:'];
        yield 'no separator' => ['content'];
        yield 'two stars' => ['content:**'];
        yield 'a star in place of the resource' => ['*:view'];
        yield 'a star and nothing else' => ['*'];
    }

    /**
     * Only the two shapes read back. A star anywhere but in place of the
     * privilege is not a wider piece, it is not a piece - see Grant::parse().
     */
    #[DataProvider('unreadable')]
    public function testAnythingElseReadsBackAsNothing(string $written): void
    {
        self::assertNull(Grant::parse($written));
    }
}
