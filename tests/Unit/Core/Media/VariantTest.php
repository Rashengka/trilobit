<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Media\Variant;

/**
 * How big each variant of a picture comes out.
 *
 * The longest side is what is fixed, so a portrait and a landscape photo of the
 * same size end up with the same number of pixels; and nothing is ever made
 * bigger than it was uploaded, because an enlarged picture is a blurred one
 * that costs more to send.
 */
#[CoversClass(Variant::class)]
final class VariantTest extends TestCase
{
    /**
     * @return iterable<string, array{Variant, int, int, int, int}>
     */
    public static function sizes(): iterable
    {
        yield 'a landscape photo, thumbnail' => [Variant::Thumb, 4000, 3000, 240, 180];
        yield 'a landscape photo, card' => [Variant::Card, 4000, 3000, 800, 600];
        yield 'a landscape photo, large' => [Variant::Large, 4000, 3000, 1600, 1200];
        yield 'a portrait photo keeps its longest side upright' => [Variant::Thumb, 3000, 4000, 180, 240];
        yield 'a picture smaller than the variant is not enlarged' => [Variant::Large, 200, 100, 200, 100];
        yield 'a picture exactly the size of the variant is left as it is' => [Variant::Card, 800, 450, 800, 450];
        yield 'a sliver keeps at least one pixel' => [Variant::Thumb, 10_000, 10, 240, 1];
    }

    #[DataProvider('sizes')]
    public function testTheLongestSideIsFittedAndNothingIsEnlarged(
        Variant $variant,
        int $width,
        int $height,
        int $expectedWidth,
        int $expectedHeight,
    ): void {
        self::assertSame([$expectedWidth, $expectedHeight], $variant->fit($width, $height));
    }

    public function testTheVariantsAreTheThreeSizesThePlanNamed(): void
    {
        $sides = [];
        foreach (Variant::cases() as $variant) {
            $sides[$variant->value] = $variant->longestSide();
        }

        self::assertSame(['thumb' => 240, 'card' => 800, 'large' => 1600], $sides);
    }
}
