<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Security\ResourceTree;

/**
 * What falls under what, read from the names alone.
 *
 * The names here are invented rather than taken from the enum, because the
 * enum is closed: a name whose parent is missing is exactly the one mistake
 * the shipped list cannot be made to contain, and it has to be possible to
 * watch reading refuse it.
 */
#[CoversClass(ResourceTree::class)]
final class ResourceTreeTest extends TestCase
{
    public function testWhatANameFallsUnderIsTheNameUpToItsLastDot(): void
    {
        $tree = ResourceTree::of(['app', 'app.shop', 'app.shop.catalogue', 'app.blog']);

        self::assertNull($tree->parentOf('app'));
        self::assertSame('app', $tree->parentOf('app.shop'));
        self::assertSame('app.shop', $tree->parentOf('app.shop.catalogue'));
    }

    /** Nearest first, and however high: a right opens every one of them. */
    public function testEverythingANameFallsUnderIsFoundHoweverHigh(): void
    {
        $tree = ResourceTree::of(['app', 'app.shop', 'app.shop.catalogue', 'app.blog']);

        self::assertSame(['app.shop', 'app'], $tree->ancestorsOf('app.shop.catalogue'));
        self::assertSame(['app'], $tree->ancestorsOf('app.blog'));
        self::assertSame([], $tree->ancestorsOf('app'));
    }

    public function testEverythingUnderANameIsFoundHoweverDeep(): void
    {
        $tree = ResourceTree::of(['app', 'app.shop', 'app.shop.catalogue', 'app.blog']);

        self::assertSame(['app.shop', 'app.shop.catalogue', 'app.blog'], $tree->descendantsOf('app'));
        self::assertSame(['app.shop.catalogue'], $tree->descendantsOf('app.shop'));
        self::assertSame([], $tree->descendantsOf('app.blog'));
    }

    /**
     * Beginning with another name is not falling under it. A whole of the
     * shop that reached the shopping list would be a right nobody gave.
     */
    public function testANameIsNotUnderAnotherItMerelyBeginsWith(): void
    {
        $tree = ResourceTree::of(['app', 'app.shop', 'app.shopping']);

        self::assertSame([], $tree->descendantsOf('app.shop'));
        self::assertSame(['app'], $tree->ancestorsOf('app.shopping'));
    }

    /**
     * A name whose parent is not there would be a resource at the top that
     * nobody put there: its pieces would open no door, and nothing would say
     * why. So reading refuses it, and the build with it.
     */
    public function testANameWhoseParentIsNotThereIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("#'app\\.shop\\.catalogue'.*'app\\.shop'#");

        ResourceTree::of(['app', 'app.shop.catalogue']);
    }

    /** An empty segment would make what a name falls under a matter of where a dot slipped. */
    public function testANameWithAnEmptySegmentIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("#'app\\.\\.catalogue'#");

        ResourceTree::of(['app', 'app..catalogue']);
    }
}
