<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Component;

/**
 * What a component refuses to be drawn with.
 *
 * A template cannot throw, and a component that cannot refuse draws whatever it
 * does not recognise as its default: a badge asked for 'danger' with a typing
 * mistake is a plain badge, a button drawn as only an icon with no icon is a
 * button with nothing on it. Both look finished and are wrong, which is the
 * mistake nobody notices. So a component asks here, and a wrong argument stops
 * the page instead - the way Icon::from() stops it for an icon nobody drew.
 */
final class Argument
{
    /**
     * @param non-empty-list<string> $allowed
     *
     * @throws \InvalidArgumentException when $given is none of $allowed
     */
    public static function oneOf(string $component, string $parameter, string $given, array $allowed): void
    {
        if (!in_array($given, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf(
                "%s takes %s '%s', not '%s'.",
                $component,
                $parameter,
                implode("', '", $allowed),
                $given,
            ));
        }
    }

    /** @throws \InvalidArgumentException carrying $refusal when $condition does not hold */
    public static function holds(bool $condition, string $refusal): void
    {
        if (!$condition) {
            throw new \InvalidArgumentException($refusal);
        }
    }
}
