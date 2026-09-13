<?php

declare(strict_types=1);

namespace Trilobit\Core\Config;

/**
 * The environment still sets TRILOBIT_DEBUG and names no mode.
 *
 * A type of its own because it is the one failure of the boot that the web
 * entry point answers itself - as plain text, before Tracy is switched on -
 * and it can answer this failure and no other only if it can tell it apart by
 * more than its wording.
 *
 * The message is fixed, which is what makes it fit to show a visitor: it names
 * two variables and says what to write instead, and nothing in it comes from
 * the machine it was thrown on. That is also why the constructor takes no
 * arguments. Any other exception thrown while booting can say things nobody
 * has read, and is left to PHP, which in production shows nothing.
 */
final class ModeNotNamed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(sprintf(
            '%2$s is set and %1$s is not. %2$s is no longer read: the mode is %1$s - dev, staging or prod - '
            . 'and without one this would start as prod, with no debug bar and no style guide. Replace %2$s '
            . 'with %1$s=dev wherever it is set (.env, compose.override.yaml, the web server or the container), '
            . 'or with %1$s=prod if production is what is meant.',
            Mode::VARIABLE,
            Mode::RETIRED,
        ));
    }
}
