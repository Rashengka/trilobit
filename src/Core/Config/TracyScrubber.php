<?php

declare(strict_types=1);

namespace Trilobit\Core\Config;

use Closure;
use Tracy\Debugger;
use Tracy\Dumper;
use Tracy\Dumper\Describer;
use Tracy\Dumper\Exposer;
use Tracy\Dumper\Value;
use WeakMap;

/**
 * Puts Trilobit\Core\Config\Secrets between Tracy and everything it prints.
 *
 * Tracy prints in two ways, and they need two different hooks.
 *
 * The error page asks its own scrubber about every key and value it dumps -
 * $_SERVER with the process environment in it, $_COOKIE, $_SESSION, $_POST
 * and the arguments of every frame. That page is shown in debug mode and
 * written to var/log in every mode, both by the same object, so one scrubber
 * covers the screen and the log.
 *
 * The debug bar's panels dump through Tracy\Dumper with no options at all -
 * the container panel, which shows every service a request created, among
 * them - so neither the scrubber nor Tracy's own list of names reaches them.
 * What does reach every dump is the table of object exporters, which is
 * static; an entry under the empty name is Tracy's fallback for an object no
 * other entry claims. The one registered here hands the object on to Tracy's
 * own exposer, having first given the dump the scrubber it was not given, so
 * the object looks exactly as it did apart from its secrets - and so does
 * everything the same dump shows after it.
 *
 * What a rule over names cannot reach, and is therefore kept out of Tracy's
 * way instead: a value dumped with no name beside it (a panel printing one
 * route parameter at a time), and a secret under a name that gives nothing
 * away. The first is why the environment is not a container parameter at all -
 * the container panel prints parameters as a bare array before it reaches any
 * object - and the second is why Trilobit\Core\Config\Environment, the one
 * object holding every value a deployment has, never shows any of them.
 *
 * The exporter and the scrubber reach into Tracy\Dumper\Describer and
 * Tracy\Dumper\Exposer, which Tracy marks internal. That is a bet a Tracy
 * upgrade may lose, and losing it would be silent - so what holds it is a test
 * of the output, Trilobit\Tests\Integration\Config\TracyKeepsTheSecretsTest,
 * not this comment.
 */
final class TracyScrubber
{
    /** @var WeakMap<Describer, true>|null the dumps already judged by the rule */
    private static ?WeakMap $judged = null;

    public static function install(): void
    {
        Debugger::getBlueScreen()->scrubber = Secrets::isSecret(...);

        // Appended, and so asked last: every exporter Tracy has for a
        // particular type still wins over this one, which claims only what
        // would otherwise have gone to the fallback.
        //
        // Tracy's own annotation of the table admits class names and static
        // method pairs only, which is narrower than what Tracy\Dumper\
        // Describer::exposeObject() reads out of it - any callable, and the
        // empty name as "every object". The line is excused by name rather
        // than slipped past the analyser through reflection, so that it stays
        // visible as the one place the two disagree.
        Dumper::$objectExporters[''] = self::expose(...); // @phpstan-ignore assign.propertyType (the empty name is Tracy's fallback, which its annotation leaves out)
    }

    /** @return array<mixed>|null */
    private static function expose(object $object, Value $value, Describer $describer): ?array
    {
        self::$judged ??= new WeakMap();
        if (!isset(self::$judged[$describer])) {
            self::$judged[$describer] = true;
            $describer->scrubber = self::judgedBySecrets($describer->scrubber);
        }

        // What Tracy does next for an object no exporter claims, repeated
        // because claiming every such object is how this gets to run at all.
        if ($describer->debugInfo && method_exists($object, '__debugInfo')) {
            $properties = $object->__debugInfo();

            return is_array($properties) ? $properties : null;
        }

        Exposer::exposeObject($object, $value, $describer);

        return null;
    }

    /**
     * @param (callable(string, mixed, ?string): bool)|null $other a scrubber the dump already has
     * @return Closure(string, mixed, ?string): bool
     */
    private static function judgedBySecrets(?callable $other): Closure
    {
        return static fn(string $key, mixed $value, ?string $class = null): bool => Secrets::isSecret($key, $value)
            || ($other !== null && $other($key, $value, $class));
    }
}
