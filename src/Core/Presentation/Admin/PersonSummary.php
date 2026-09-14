<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

use Trilobit\Core\Domain\User\User;

/** One row of the list of people, worked out before the template draws it. */
final readonly class PersonSummary
{
    /** Signs in, as ever. */
    public const string ACTIVE = 'Active';

    /** Added, and has not yet set a password with the link they were sent. */
    public const string INVITED = 'Invited';

    /** Switched off by the installation's administrator; cannot sign in. */
    public const string SWITCHED_OFF = 'Switched off';

    /**
     * @param list<string> $roles the names of the roles held here, sorted
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public array $roles,
        public string $state,
        public string $personUrl,
        public bool $isYou,
    ) {}

    /** Which of the three an account is, in the words the list shows. */
    public static function stateOf(User $account): string
    {
        return match (true) {
            !$account->isActive() => self::SWITCHED_OFF,
            !$account->hasPassword() => self::INVITED,
            default => self::ACTIVE,
        };
    }
}
