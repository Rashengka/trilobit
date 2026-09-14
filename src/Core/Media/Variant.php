<?php

declare(strict_types=1);

namespace Trilobit\Core\Media;

/**
 * The sizes a picture is published in, one file each, for a `srcset` to choose
 * from.
 *
 * What is fixed is the longest side, so that a portrait and a landscape photo
 * of the same size cost the same to send. A picture is never made bigger than
 * it was uploaded: an enlarged picture is a blurred one that costs more, and a
 * browser scales up for free. A picture smaller than a variant therefore has
 * that variant at its own size - still written, and still stripped of what the
 * original carries, because the original is never published.
 */
enum Variant: string
{
    case Thumb = 'thumb';
    case Card = 'card';
    case Large = 'large';

    public function longestSide(): int
    {
        return match ($this) {
            self::Thumb => 240,
            self::Card => 800,
            self::Large => 1600,
        };
    }

    /**
     * The size a picture of $width by $height comes out at in this variant.
     *
     * @return array{int, int} width and height
     */
    public function fit(int $width, int $height): array
    {
        $longest = max($width, $height);
        $side = $this->longestSide();

        if ($longest <= $side) {
            return [$width, $height];
        }

        return [
            max(1, (int) round($width * $side / $longest)),
            max(1, (int) round($height * $side / $longest)),
        ];
    }
}
