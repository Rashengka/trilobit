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

    private const string PREFERENCES = 'preferences';

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
            self::PREFERENCES => $account->preferences(),
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

    /**
     * What this person has chosen about the way the application is drawn, as it
     * stood when they signed in.
     *
     * It is carried here so that the moment of signing in - where the profile
     * takes over from the device (decision D8) - costs no query. It is a
     * snapshot like everything else on an identity, so a change made later in
     * the session is written to the account and not back into here; nothing
     * reads it again before the next sign-in, which rebuilds it.
     *
     * @return array<string, string>
     */
    public function preferences(): array
    {
        $preferences = $this->getData()[self::PREFERENCES] ?? [];
        if (!is_array($preferences)) {
            return [];
        }

        $chosen = [];
        foreach ($preferences as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $chosen[$name] = $value;
            }
        }

        return $chosen;
    }
}
