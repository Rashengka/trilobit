<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Config;

/**
 * A service configuration hands a secret to, the way a mailer is handed its
 * password or a payment client its key.
 *
 * It stands in for the next such service because none exists yet, and the one
 * that holds a secret today - the database connection - is built by a factory,
 * which Nette never makes lazy. This one the container constructs itself, so
 * with lazy services on it is handed out unbuilt, which is the state most of a
 * request's services are in when the debug bar is drawn.
 */
final readonly class HoldsASecret
{
    public function __construct(private string $apiToken) {}

    public function apiToken(): string
    {
        return $this->apiToken;
    }
}
