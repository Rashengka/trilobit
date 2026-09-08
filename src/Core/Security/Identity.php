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
 * **The roles on it are what answers, and they are therefore not a snapshot.**
 * Nette\Security\User::isAllowed() walks getRoles() and asks an authorizator
 * about each one, so these names are the question rather than a decoration
 * beside it (decision D3). A set copied in when somebody signed in would go on
 * answering after the right behind it was taken away, with nothing to look at
 * that says so - which is why Trilobit\Core\Security\Authenticator reads them
 * again at the start of every request.
 *
 * **They belong to one business and the identity says which.** A role is held
 * in a tenant - see Trilobit\Core\Domain\Tenancy\Membership - so a set with no
 * business beside it would be a set that could be asked in the wrong one. What
 * it records is what the set was read for, never where the answer comes from:
 * the business of a request is settled from its host, every request, by
 * Trilobit\Core\Tenancy\TenantFromHost.
 *
 * The permissions are a snapshot, for the same reason as the rest of the data,
 * and are refreshed the next time somebody signs in. Nothing reads them to
 * decide anything - they are drawn on the overview page and no more; see
 * .ai/plans/08 decision D2 and its exit condition.
 */
final class Identity extends SimpleIdentity
{
    private const string EMAIL = 'email';

    private const string NAME = 'name';

    private const string PERMISSIONS = 'permissions';

    private const string PREFERENCES = 'preferences';

    /**
     * The tenant the roles on this identity were read for, or null when they
     * were not read for one - which is the same thing as there being none.
     */
    private ?int $rolesLoadedFor = null;

    /**
     * The identity of an account, holding nothing yet.
     *
     * No roles, and deliberately not the ones the account itself was granted:
     * core_user_role has no business in it, so a right read off it would be a
     * right in every business at once. What may be done here is put on by
     * holdingRolesIn() as soon as it is settled where here is.
     */
    public static function of(User $account): self
    {
        $id = $account->id();
        if ($id === null) {
            throw new \LogicException('An account that has never been saved cannot be signed in as.');
        }

        return new self($id, [], [
            self::EMAIL => $account->email(),
            self::NAME => $account->name(),
            self::PERMISSIONS => $account->permissions(),
            self::PREFERENCES => $account->preferences(),
        ]);
    }

    /**
     * The same person, carrying the roles they hold in $tenant.
     *
     * A copy rather than a change, because the identity this is made from may
     * be the one the framework is holding for this request: writing rights into
     * it would be writing them into something somebody is already reading.
     *
     * @param list<string> $roles
     */
    public function holdingRolesIn(int $tenant, array $roles): self
    {
        $carrying = $this->copyHolding($roles);
        $carrying->rolesLoadedFor = $tenant;

        return $carrying;
    }

    /**
     * The same person, carrying nothing at all.
     *
     * It is what goes into the session and it is what somebody gets before it
     * is settled whose request this is. Both are the same sentence: an identity
     * that carries no rights cannot answer with the wrong ones, so the way this
     * fails is by refusing rather than by allowing.
     */
    public function holdingNothing(): self
    {
        return $this->copyHolding([]);
    }

    /** Which tenant the roles on this identity were read for; null when none were. */
    public function rolesLoadedFor(): ?int
    {
        return $this->rolesLoadedFor;
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

    /**
     * The same person and the same data, with this set of roles and no business
     * recorded beside it.
     *
     * @param list<string> $roles
     */
    private function copyHolding(array $roles): self
    {
        return new self($this->getId(), $roles, $this->getData());
    }
}
