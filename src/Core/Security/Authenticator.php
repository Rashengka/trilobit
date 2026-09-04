<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Security\AuthenticationException;
use Nette\Security\Authenticator as NetteAuthenticator;
use Nette\Security\IIdentity;
use Nette\Security\Passwords;
use Trilobit\Core\Domain\User\User;

/**
 * Checks an address and a password against the accounts in the database.
 *
 * Three things here are decisions rather than plumbing.
 *
 * **The refusal says nothing.** Whichever of the four ways to fail happened,
 * the message a visitor sees is the same one; the code differs so that the
 * application can tell them apart in a log. An error page distinguishing
 * "no such address" from "wrong password" is an address checker anybody can
 * run.
 *
 * **A missing account costs the same as a present one.** Hashing is slow on
 * purpose, so returning early when nobody has that address would let a
 * stopwatch answer the question the message refuses to. The work is done and
 * thrown away.
 *
 * **A hash that is out of date is replaced on the way past.** It is the only
 * moment the password is in hand, so it is the only moment a cost factor
 * raised in a later PHP release can be applied to an account that already
 * exists.
 */
final readonly class Authenticator implements NetteAuthenticator
{
    /**
     * The one sentence every refusal carries. It is here rather than in the
     * presenter so that the reason for its vagueness sits next to the code
     * that would otherwise be tempted to be helpful.
     */
    public const string REFUSAL = 'The address or the password is not right.';

    public function __construct(
        private Accounts $accounts,
        private Passwords $passwords,
        private EntityManagerInterface $entityManager,
    ) {}

    /** @throws AuthenticationException */
    public function authenticate(string $user, string $password): IIdentity
    {
        $account = $this->accounts->withEmail($user);

        if (!$account instanceof User) {
            $this->passwords->hash($password);

            throw new AuthenticationException(self::REFUSAL, self::IdentityNotFound);
        }

        if (!$this->passwords->verify($password, $account->passwordHash())) {
            throw new AuthenticationException(self::REFUSAL, self::InvalidCredential);
        }

        if (!$account->isActive()) {
            throw new AuthenticationException(self::REFUSAL, self::NotApproved);
        }

        if ($this->passwords->needsRehash($account->passwordHash())) {
            $account->changePassword($this->passwords->hash($password));
        }

        $account->signedIn(new DateTimeImmutable());
        $this->entityManager->flush();

        return Identity::of($account);
    }
}
