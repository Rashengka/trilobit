<?php

declare(strict_types=1);

namespace Trilobit\Core\Seed;

use Trilobit\Core\Security\Grant;

/**
 * Somebody a module wants `app:seed` to make in a business: an account holding
 * a role of the business's own, assembled out of $grants.
 *
 * The module says who and what; the seed makes the account, generates and
 * prints the password, and writes the address, so that every account the seed
 * makes is made one way and printed in the one shape
 * Trilobit\Core\Console\SeedCommand::ACCOUNT_LINE promises. The address is the
 * business's first word, a hyphen and $mailbox, at example.com - the pattern
 * the seed's own accounts follow.
 */
final readonly class SeededMember
{
    /**
     * @param string $mailbox what comes after the business in the address: `cataloguer`
     * @param list<Grant> $grants what the role is made of
     * @param string $description what the account is for, as the report prints it
     */
    public function __construct(
        public string $mailbox,
        public string $name,
        public string $roleCode,
        public string $roleName,
        public array $grants,
        public string $description,
    ) {}
}
