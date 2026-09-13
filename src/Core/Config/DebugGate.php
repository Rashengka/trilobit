<?php

declare(strict_types=1);

namespace Trilobit\Core\Config;

use SensitiveParameter;

/**
 * Whether a request on staging may see the debugger: it may when it carries a
 * cookie holding the secret the deployment names in TRILOBIT_DEBUG_SECRET.
 *
 * Staging runs over real data, and the debug bar and the detailed error page
 * show that data - the queries, the variables, the request - to whoever the
 * page is open to. The whole of staging is meant to stand behind HTTP
 * authentication on its web server or proxy; this is the second lock, on the
 * one part of it that shows more than the application's own pages do.
 *
 * It is a check of its own rather than the framework's detection
 * (Nette\Bootstrap\Configurator::detectDebugMode()), because that one does not
 * fit a deployment behind a proxy. The cookie alone does not open it: what is
 * matched is "secret@address", and behind a proxy the address is the proxy's,
 * for everybody - the detection runs before there is a container, so the
 * framework's list of trusted proxies is not there to correct it. A request
 * from 127.0.0.1 without a forwarding header is let in with no secret at all,
 * which is every request a proxy on the same machine passes on without adding
 * one. And it compares with a plain equality, under a cookie called
 * nette-debug, which anybody probing a Nette application knows to try.
 *
 * Every way of it going wrong shuts the gate: no secret, one shorter than
 * SHORTEST_SECRET, no cookie, a cookie that is not a string, a cookie that
 * differs. A deployment that forgot the secret is then a staging without its
 * debugger, which somebody misses and fixes, rather than one whose debugger is
 * open to anybody - the same direction Mode takes with a mode nobody named.
 *
 * Only the answer is kept. Neither the secret nor the cookie is a property
 * here, so no dump of this object has either to show, and both parameters of
 * check() are marked sensitive, so a stack trace through it shows neither.
 */
final readonly class DebugGate
{
    /**
     * The variable the secret is read from.
     *
     * Its name says what it holds, with the word "secret" on its own, so that
     * a rule hiding values from error pages by the words in their names
     * catches it wherever it turns up - in $_SERVER, in the process
     * environment, in a dump.
     */
    public const string VARIABLE = 'TRILOBIT_DEBUG_SECRET';

    /**
     * The cookie the secret is looked for in.
     *
     * The application's own name rather than one of the framework's, which
     * scanners know to try, and a fixed one rather than a setting. What keeps
     * the gate shut is the value, which is at least SHORTEST_SECRET characters
     * nobody can guess; a name chosen per deployment would add nothing to that
     * but a second setting to get wrong, and one that could be set to a name
     * the rule hiding secrets from error pages does not recognise. This one
     * carries the word "secret" for the same reason VARIABLE does.
     */
    public const string COOKIE = 'trilobit-debug-secret';

    /**
     * The fewest characters a secret may have and count.
     *
     * Enough that guessing is out of the question for anything reachable over
     * HTTP, and few enough that nobody is tempted to shorten it: a generator
     * such as `openssl rand -hex 32` gives twice as many.
     */
    public const int SHORTEST_SECRET = 32;

    private function __construct(
        private bool $open,
    ) {}

    /**
     * @param array<mixed> $cookies the request's cookies, as PHP read them
     */
    public static function check(
        #[SensitiveParameter]
        Environment $environment,
        #[SensitiveParameter]
        array $cookies,
    ): self {
        $expected = $environment->value(self::VARIABLE);
        $presented = $cookies[self::COOKIE] ?? null;

        // hash_equals() takes as long for a guess wrong in its first character
        // as for one wrong in its last, so how long an answer took says
        // nothing about how much of a guess was right. The length is checked
        // first because an empty secret would otherwise match an empty cookie.
        return new self(
            strlen($expected) >= self::SHORTEST_SECRET
            && is_string($presented)
            && hash_equals($expected, $presented),
        );
    }

    public function isOpen(): bool
    {
        return $this->open;
    }
}
