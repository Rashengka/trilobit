<?php

declare(strict_types=1);

namespace Trilobit\Core\Setup;

use SensitiveParameter;

/**
 * Where the database was looked for and what came back, for the one reader
 * allowed to see it: somebody setting up a working copy.
 *
 * It exists only in debug mode - Trilobit\Core\Setup\Progress does not make one
 * otherwise - so a production page cannot show it by mistake, because there
 * is nothing to show. The password is not in it in any mode; the driver's
 * message does not carry one either, only whether one was used.
 */
final readonly class Attempt
{
    public function __construct(
        public string $host,
        public string $port,
        public string $name,
        public string $user,
        /** What the database driver said, in its own words. */
        public string $reason,
    ) {}

    /**
     * @param array<mixed> $parameters the connection's own parameters,
     *     password included - which is why the parameter is marked sensitive
     *     and only four entries are ever read out of it
     */
    public static function of(#[SensitiveParameter] array $parameters, \Throwable $failure): self
    {
        return new self(
            self::text($parameters, 'host'),
            self::text($parameters, 'port'),
            self::text($parameters, 'dbname'),
            self::text($parameters, 'user'),
            $failure->getMessage(),
        );
    }

    /** @param array<mixed> $parameters */
    private static function text(#[SensitiveParameter] array $parameters, string $key): string
    {
        $value = $parameters[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
