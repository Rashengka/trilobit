<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Listing;

/**
 * How a filter compares its field with what was chosen or typed.
 *
 * It is said on every filter and never worked out from the control the value
 * is entered with (.ai/plans/15, decision 3). A line of text may mean "equal
 * to", "containing" or "starting with", and which one is a decision about the
 * column rather than about the control - worked out from the control, the
 * first column that wanted it otherwise would need an exception in the
 * ancestor every listing shares.
 *
 * What was typed never becomes part of the query: condition() names a
 * parameter and bound() is what the parameter is bound to.
 *
 * **LIKE is escaped by a character of its own.** `%` and `_` mean something
 * to LIKE, and somebody looking for "50%" is looking for those three
 * characters. The escape is `!`, stated in the condition, rather than the
 * backslash LIKE uses by default: with NO_BACKSLASH_ESCAPES in the server's
 * SQL mode the backslash stops escaping, and "50%" would quietly go back to
 * meaning "anything starting with 50".
 */
enum Comparison
{
    case Equals;
    case Contains;
    case StartsWith;

    private const string ESCAPE = '!';

    /** The condition over $column against the bound parameter named $parameter. */
    public function condition(string $column, string $parameter): string
    {
        return match ($this) {
            self::Equals => sprintf('%s = :%s', $column, $parameter),
            self::Contains, self::StartsWith => sprintf("%s LIKE :%s ESCAPE '%s'", $column, $parameter, self::ESCAPE),
        };
    }

    /** What the parameter of condition() is bound to, for $value as it was typed. */
    public function bound(string $value): string
    {
        $literal = strtr($value, [
            self::ESCAPE => self::ESCAPE . self::ESCAPE,
            '%' => self::ESCAPE . '%',
            '_' => self::ESCAPE . '_',
        ]);

        return match ($this) {
            self::Equals => $value,
            self::Contains => '%' . $literal . '%',
            self::StartsWith => $literal . '%',
        };
    }
}
