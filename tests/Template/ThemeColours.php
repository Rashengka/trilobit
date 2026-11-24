<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

/**
 * The colours a theme file declares, resolved the way a browser resolves them
 * for one mode, and the contrast between two of them as WCAG 2 measures it.
 *
 * A theme writes every colour as a light-dark() pair of oklch() colours, or as
 * a var() naming another token of the same theme. That is all this reads, and
 * a value in any other shape fails loudly rather than being measured as black:
 * a measurement that quietly read nothing would pass every pair it was asked
 * about.
 *
 * oklch() is turned into sRGB through OKLab with the matrices CSS Color 4
 * gives. A colour outside sRGB is clamped channel by channel, which is close
 * to - though not exactly - what a browser's gamut mapping paints;
 * tests/e2e/foundations.spec.ts measures the painted pixels as well.
 */
final readonly class ThemeColours
{
    public const string LIGHT = 'light';

    public const string DARK = 'dark';

    /** @param array<string, string> $declarations name => the value it is first declared with */
    private function __construct(private array $declarations) {}

    public static function of(string $source): self
    {
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
        preg_match_all('/^\s*(--[a-z0-9-]+)\s*:\s*([^;]+);/mi', $source, $matches, PREG_SET_ORDER);

        $declarations = [];
        foreach ($matches as $match) {
            $declarations[$match[1]] ??= trim((string) preg_replace('/\s+/', ' ', $match[2]));
        }

        return new self($declarations);
    }

    /** @return list<string> every --color-* token the theme declares */
    public function tokens(): array
    {
        return array_values(array_filter(
            array_keys($this->declarations),
            static fn(string $token): bool => str_starts_with($token, '--color-'),
        ));
    }

    public function value(string $token): string
    {
        if (!array_key_exists($token, $this->declarations)) {
            throw new \InvalidArgumentException(sprintf('The theme does not declare %s.', $token));
        }

        return $this->declarations[$token];
    }

    /**
     * The colour a token gives in one mode, as linear sRGB channels between 0
     * and 1 and an alpha.
     *
     * @return array{float, float, float, float}
     */
    public function resolve(string $token, string $mode): array
    {
        return $this->colour($this->value($token), $mode, [$token]);
    }

    /** The contrast ratio of two tokens in one mode, from 1 to 21. */
    public function contrast(string $one, string $two, string $mode): float
    {
        $first = $this->luminance($this->resolve($one, $mode));
        $second = $this->luminance($this->resolve($two, $mode));

        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }

    /**
     * @param array{float, float, float, float} $colour
     */
    private function luminance(array $colour): float
    {
        if ($colour[3] < 1.0) {
            throw new \InvalidArgumentException('A translucent colour has no contrast of its own to measure.');
        }

        return 0.2126 * $colour[0] + 0.7152 * $colour[1] + 0.0722 * $colour[2];
    }

    /**
     * @param list<string> $seen the tokens already followed, so a loop fails instead of recursing for ever
     *
     * @return array{float, float, float, float}
     */
    private function colour(string $value, string $mode, array $seen): array
    {
        if (preg_match('/^var\(\s*(--[a-z0-9-]+)\s*\)$/i', $value, $reference) === 1) {
            if (in_array($reference[1], $seen, true)) {
                throw new \InvalidArgumentException(sprintf('%s refers to itself.', $reference[1]));
            }

            return $this->colour($this->value($reference[1]), $mode, [...$seen, $reference[1]]);
        }

        if (preg_match('/^light-dark\((.*)\)$/i', $value, $pair) === 1) {
            $halves = $this->splitAtTopLevelComma($pair[1]);

            return $this->colour(trim($mode === self::LIGHT ? $halves[0] : $halves[1]), $mode, $seen);
        }

        $number = '(-?\d*\.?\d+%?)';
        if (preg_match('/^oklch\(\s*' . $number . '\s+' . $number . '\s+' . $number . '\s*(?:\/\s*' . $number . ')?\s*\)$/i', $value, $parts) === 1) {
            return $this->fromOklch(
                $this->fraction($parts[1]),
                (float) $parts[2],
                (float) $parts[3],
                isset($parts[4]) ? $this->fraction($parts[4]) : 1.0,
            );
        }

        throw new \InvalidArgumentException(sprintf(
            '"%s" is neither a light-dark() pair, an oklch() colour nor a var() of one, so it cannot be measured.',
            $value,
        ));
    }

    /** @return array{string, string} */
    private function splitAtTopLevelComma(string $arguments): array
    {
        $depth = 0;
        $length = strlen($arguments);
        for ($at = 0; $at < $length; $at++) {
            $character = $arguments[$at];
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                return [substr($arguments, 0, $at), substr($arguments, $at + 1)];
            }
        }

        throw new \InvalidArgumentException(sprintf('light-dark(%s) does not hold two colours.', $arguments));
    }

    private function fraction(string $number): float
    {
        return str_ends_with($number, '%') ? (float) substr($number, 0, -1) / 100 : (float) $number;
    }

    /** @return array{float, float, float, float} */
    private function fromOklch(float $lightness, float $chroma, float $hue, float $alpha): array
    {
        $a = $chroma * cos(deg2rad($hue));
        $b = $chroma * sin(deg2rad($hue));

        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        $clamp = static fn(float $channel): float => min(1.0, max(0.0, $channel));

        return [
            $clamp(4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s),
            $clamp(-1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s),
            $clamp(-0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s),
            $alpha,
        ];
    }
}
