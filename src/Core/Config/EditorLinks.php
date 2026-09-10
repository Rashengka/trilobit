<?php

declare(strict_types=1);

namespace Trilobit\Core\Config;

/**
 * What Tracy needs in order to turn a line of a stack trace into a link that
 * opens that line in an editor.
 *
 * It is a class of its own rather than four lines inside
 * Trilobit\Core\Bootstrap because of the second method: the rewriting is done
 * by Tracy with strtr(), whose behaviour has a trap in it, and a trap is worth
 * a test. Setting a static property is not testable; producing the value that
 * goes into it is.
 *
 * **The trap, and it was met rather than foreseen.** Tracy applies the mapping
 * as `strtr($file, $mapping)`, which replaces the key wherever it occurs and
 * not only where the path begins. The application's root inside the container
 * is `/app`, so a mapping keyed on `/app` also rewrote the middle of
 * `/app/vendor/nette/application/src/...` - the `/app` in `application` - and
 * produced a link to a file that has never existed on any machine. It opened
 * nothing, and nothing said why. Keying on the root *with its trailing
 * separator* is what makes the key unable to match inside a longer directory
 * name, because what follows `/app` there is a letter and not a separator.
 *
 * **Exit condition:** the day the checkout itself contains a directory whose
 * path repeats the root - `/app/app/...` - because strtr() would rewrite that
 * one twice. There is none today and the answer then is not another key but
 * not using strtr() at all, which means not using Tracy's mapping.
 */
final readonly class EditorLinks
{
    /**
     * The scheme every JetBrains editor registers.
     *
     * It is the default because this application is also the corpus the Latte
     * plugin beside it is developed against (CLAUDE.md, §8), and because a
     * pattern that is wrong costs nothing: the link is offered, the operating
     * system has nothing registered for the scheme, and nothing happens.
     */
    public const string EDITOR = 'phpstorm://open?file=%file&line=%line';

    /** Where a line of a stack trace is sent, unless this deployment names something else. */
    public static function pattern(Environment $environment): string
    {
        return $environment->value('TRILOBIT_EDITOR', self::EDITOR);
    }

    /**
     * What the paths in a stack trace have to be rewritten to before an editor
     * can be asked to open one, or nothing at all where this deployment has not
     * said.
     *
     * Saying nothing is the answer that leaves the links naming the path the
     * application really sees, which is already right wherever the editor sees
     * the same one. It is also the only safe default: the application runs in a
     * container and cannot discover what its own directory is called outside
     * one, and a public repository must not carry the answer for somebody
     * else's machine. A wrong mapping opens the wrong file.
     *
     * @param string $root the application's own directory, as this process sees it
     *
     * @return array<string, string>
     */
    public static function mapping(Environment $environment, string $root): array
    {
        $onTheEditorsMachine = $environment->value('TRILOBIT_EDITOR_ROOT');
        if ($onTheEditorsMachine === '') {
            return [];
        }

        // Both sides carry the separator, for the reason in the class docblock:
        // it is what stops the key matching inside a directory whose name
        // merely begins with the root's own.
        return [rtrim($root, '/') . '/' => rtrim($onTheEditorsMachine, '/') . '/'];
    }
}
