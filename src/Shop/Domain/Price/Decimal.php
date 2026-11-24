<?php

declare(strict_types=1);

namespace Trilobit\Shop\Domain\Price;

/**
 * A number somebody typed with decimal places, read as the whole number of
 * the smallest unit it names - `12 990,50` as 1299050 hundredths - without a
 * float anywhere on the way.
 *
 * A float would do for most prices and get some of them wrong by one unit, and
 * which ones depends on the number: a rounding rule that works "almost always"
 * is one nobody can test. So the digits are read as digits.
 *
 * What is taken is what people write: digits, one dot or one comma before the
 * decimal places, and spaces - ordinary or not breaking - between the
 * thousands. What is refused is anything that would be a guess: a sign, more
 * decimal places than the unit has, and a dot and a comma together, which one
 * country writes as twelve hundred and another as a little over one.
 */
final class Decimal
{
    /**
     * How many digits the whole part may have. Enough for any price or rate,
     * and few enough that multiplying it by the unit cannot overflow an
     * integer on any platform PHP runs on.
     */
    private const int MAX_WHOLE_DIGITS = 15;

    /** The spaces people put between thousands: an ordinary one, one that does not break, and the narrow one. */
    private const string SPACES = "/[ \t\u{00A0}\u{202F}]+/u";

    /**
     * $written in units of 10^-$places, or null when it cannot be read as a
     * number with at most $places decimal places.
     */
    public static function scaled(string $written, int $places): ?int
    {
        if ($places < 1) {
            throw new \LogicException('A decimal read here has at least one decimal place; a whole number is an integer.');
        }

        $digits = preg_replace(self::SPACES, '', $written);
        if (!is_string($digits)) {
            return null;
        }

        $pattern = sprintf('/^(\d{1,%d})(?:[.,](\d{1,%d}))?$/', self::MAX_WHOLE_DIGITS, $places);
        if (preg_match($pattern, $digits, $parts) !== 1) {
            return null;
        }

        return (int) $parts[1] * 10 ** $places + (int) str_pad($parts[2] ?? '', $places, '0');
    }
}
