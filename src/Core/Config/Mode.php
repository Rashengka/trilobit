<?php

declare(strict_types=1);

namespace Trilobit\Core\Config;

/**
 * Which kind of deployment this is, read from TRILOBIT_ENV: dev, staging or
 * prod.
 *
 * One value with three states rather than a flag per behaviour, because flags
 * combine: three of them make eight deployments, six of which nobody asked for,
 * and the one that does harm - a debugger left on in production - is a
 * combination nobody chose, only forgot to undo. Every question that differs
 * between the three is a method here, so that it is answered once and asked
 * everywhere else, rather than decided again by a condition wherever it comes
 * up.
 *
 * An absent, empty or unknown value is production. That is the safe direction:
 * whoever forgets to set it gets an application that shows nothing, not one
 * that shows its stack traces. The names are matched exactly, so a misspelling
 * is production too rather than a guess at what was meant.
 *
 * TRILOBIT_DEBUG was the flag before this, and the one state of it that
 * mattered - on - described a machine somebody develops on. Reading such a
 * machine as production would take its debugger and its style guide away
 * without a word, so that combination refuses to start instead; see
 * fromEnvironment().
 */
enum Mode: string
{
    /** A working copy. */
    case Dev = 'dev';

    /** Debugged like a working copy and run over real data, like production. */
    case Staging = 'staging';

    /** A live deployment. */
    case Prod = 'prod';

    /** The variable the mode is read from. */
    public const string VARIABLE = 'TRILOBIT_ENV';

    /** The flag the mode replaced, read only in order to refuse it on its own. */
    public const string RETIRED = 'TRILOBIT_DEBUG';

    /**
     * @throws \RuntimeException where the retired flag is on and no mode is
     *     named, because the fallback to production would there switch off
     *     what the flag had switched on - the one change-over that would
     *     otherwise go unnoticed until somebody missed the debugger
     */
    public static function fromEnvironment(Environment $environment): self
    {
        $value = $environment->value(self::VARIABLE);

        if ($value === '' && $environment->flag(self::RETIRED)) {
            throw new \RuntimeException(sprintf(
                '%2$s is set and %1$s is not. %2$s is no longer read: the mode is %1$s - dev, staging or prod - '
                . 'and without one this would start as prod, with no debug bar and no style guide. Replace %2$s '
                . 'with %1$s=dev wherever it is set (.env, compose.override.yaml, the web server or the container), '
                . 'or with %1$s=prod if production is what is meant.',
                self::VARIABLE,
                self::RETIRED,
            ));
        }

        return self::tryFrom($value) ?? self::Prod;
    }

    /** Whether the debug bar and the detailed error page are on. */
    public function debugMode(): bool
    {
        return $this !== self::Prod;
    }

    /**
     * Whether the build has a style guide at /_styleguide, where nothing more
     * specific says otherwise. In production it would be a page with nothing
     * behind it.
     */
    public function hasStyleguide(): bool
    {
        return $this !== self::Prod;
    }

    /**
     * Whether a tool may seed data or delete it - the one question a command
     * that does either asks before it starts.
     *
     * Only on a working copy. Staging is debugged like one, but its data is
     * real: the only difference between it and production is who is looking,
     * so it is refused exactly what production is.
     */
    public function mayAlterData(): bool
    {
        return $this === self::Dev;
    }
}
