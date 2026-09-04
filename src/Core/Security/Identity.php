<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

use Nette\Security\SimpleIdentity;
use Trilobit\Core\Domain\User\User;

/**
 * Who is signed in, as the rest of the application is allowed to see them.
 *
 * It is what goes into the session, so it carries a copy of the account rather
 * than the account itself - a Doctrine entity in a session is an object that
 * comes back detached from the manager that loaded it, and every read from it
 * afterwards is a read of whatever was true when it was stored.
 *
 * What it deliberately does not carry is the password hash. Nothing above this
 * needs it, and a value that is never put into the session is a value that
 * cannot leak out of one.
 *
 * The permissions are a snapshot too, for the same reason as the rest, and are
 * refreshed the next time somebody signs in. Nothing in Core enforces them yet
 * - the administration is gated on being signed in and no more; see
 * .ai/plans/08 decision D2 and its exit condition.
 */
final class Identity extends SimpleIdentity
{
    private const string EMAIL = 'email';

    private const string NAME = 'name';

    private const string PERMISSIONS = 'permissions';

    public static function of(User $account): self
    {
        $id = $account->id();
        if ($id === null) {
            throw new \LogicException('An account that has never been saved cannot be signed in as.');
        }

        return new self($id, $account->roleCodes(), [
            self::EMAIL => $account->email(),
            self::NAME => $account->name(),
            self::PERMISSIONS => $account->permissions(),
        ]);
    }

    public function email(): string
    {
        $email = $this->getData()[self::EMAIL] ?? '';

        return is_string($email) ? $email : '';
    }

    /**
     * Not getName(): SimpleIdentity answers every getX() out of its data
     * through __call, so a method of that name would be shadowing something
     * rather than adding it.
     */
    public function displayName(): string
    {
        $name = $this->getData()[self::NAME] ?? '';

        return is_string($name) ? $name : '';
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $permissions = $this->getData()[self::PERMISSIONS] ?? [];
        if (!is_array($permissions)) {
            return [];
        }

        return array_values(array_filter($permissions, is_string(...)));
    }
}
