<?php

declare(strict_types=1);

namespace Trilobit\Core\Config;

/**
 * Which values must not be shown, judged by the name they are kept under and,
 * for two shapes a name cannot give away, by the value itself.
 *
 * It is a rule rather than a list of names on purpose. A list is right on the
 * day it is written and silently wrong on the day somebody adds a setting it
 * does not mention - which is exactly how the database password came to be
 * shown: Tracy hides a fixed list of names, matched whole, and
 * TRILOBIT_DB_PASSWORD is not on it. A name here is split into its words -
 * at underscores, dashes, dots, spaces, camel case and digits - and it is
 * secret when one of those words is. TRILOBIT_MAILER_PASSWORD, apiToken and
 * password2 are then secret without anybody having written them down.
 *
 * Matching words rather than substrings is what keeps the rule usable: author
 * is not auth, keyword is not key, passenger is not pass. What it still hides
 * that is not secret - AUTH_TYPE, a cacheKey - costs a click while debugging;
 * a secret it shows costs a password rotated, so the rule leans that way.
 *
 * Its reach is where it is asked. See Trilobit\Core\Config\TracyScrubber for
 * where that is, and for what no rule over names can reach.
 */
final class Secrets
{
    /** A name holding one of these words is secret wherever the word stands in it. */
    private const array WORDS = [
        // Tracy's own list, so that nothing it hid by default is shown now.
        'password', 'passwd', 'pass', 'pwd', 'creditcard', 'cc', 'pin', 'authorization',
        // What else a secret is called.
        'passphrase', 'pw', 'secret', 'token', 'auth', 'credential', 'salt', 'signature', 'dsn',
        // A cookie is how a browser proves who it is, and the header carrying
        // all of them carries the session with them.
        'cookie',
        // The session cookie's own name, spelled as one word.
        'phpsessid', 'sessid', 'sessionid',
        'apikey', 'privatekey',
    ];

    /**
     * Secret only as part of a longer name. On its own it is the most common
     * argument name in PHP and almost never a secret; api_key, privateKey and
     * APP_KEY almost always are.
     */
    private const string KEY = 'key';

    public static function isSecret(string $name, mixed $value): bool
    {
        // An object is never hidden by the name it is kept under. Its
        // properties are asked the same question one by one, so a secret it
        // holds is hidden where it is; hiding the whole object would take a
        // Latte token or the session service away from the page that is there
        // to debug them.
        if (is_object($value)) {
            return false;
        }

        return self::isSecretName($name) || self::isSecretValue($value);
    }

    public static function isSecretName(string $name): bool
    {
        $words = self::words($name);
        $joined = implode('', $words);

        if (in_array($joined, self::WORDS, true)) {
            return true;
        }

        foreach ($words as $word) {
            $singular = str_ends_with($word, 's') ? substr($word, 0, -1) : $word;
            if (in_array($word, self::WORDS, true) || in_array($singular, self::WORDS, true)) {
                return true;
            }

            if ($singular === self::KEY && count($words) > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The two secrets a name cannot give away.
     *
     * A connection URL carries its password inside the value, under a name as
     * innocent as DATABASE_URL. And the session cookie can be given any name
     * at all, while its value is always the identifier of the session this
     * request is in - which is all it takes to be somebody else.
     */
    public static function isSecretValue(mixed $value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }

        if (preg_match('~^[a-z][a-z0-9+.\-]*://[^/?#@\s]*:[^/?#@\s]*@~i', $value) === 1) {
            return true;
        }

        $session = session_id();

        return is_string($session) && $session !== '' && $value === $session;
    }

    /** @return list<string> */
    private static function words(string $name): array
    {
        $separated = preg_replace(
            ['/([a-z])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/', '/([A-Za-z])(\d)/', '/(\d)([A-Za-z])/'],
            '$1 $2',
            $name,
        );

        $words = preg_split('/[^a-z0-9]+/', strtolower($separated ?? $name), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }
}
