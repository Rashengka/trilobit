<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Security\AuthenticationException;
use Nette\Security\Authenticator as NetteAuthenticator;
use Nette\Security\IdentityHandler;
use Nette\Security\IIdentity;
use Nette\Security\Passwords;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Tenancy\Tenancy;

/**
 * Checks an address and a password against the accounts in the database, and
 * says what the person signing in holds while they are here.
 *
 * Three things about signing in are decisions rather than plumbing.
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
 *
 * **The other half of this class is Nette\Security\IdentityHandler, and it is
 * what keeps rights current.** Decision D3 made the roles on the identity the
 * thing that answers, and the framework's own hook for that is
 * wakeupIdentity(): Nette\Security\User::loadStoredData() calls it once per
 * request, right after the identity is read out of the session, and its
 * docblock names the use in so many words - "typically refreshes roles". So
 * that is where the set is read again, and the alternative is the failure this
 * whole slice exists against: a set copied in at sign-in goes on allowing what
 * an administrator has taken away, and looks exactly like a set that is right.
 */
final readonly class Authenticator implements NetteAuthenticator, IdentityHandler
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
        private Tenancy $tenancy,
        private Memberships $memberships,
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

        return $this->holdingWhatIsHeldHere(Identity::of($account));
    }

    /**
     * What goes into the session, and it is on purpose that it is not much.
     *
     * **No rights are stored.** The roles are taken off before the identity is
     * written down, so the session carries who somebody is and nothing about
     * what they may do. Two things follow from that and both are the reason.
     * A stored set would be a second answer to a question the database already
     * answers, and the two would part company the moment somebody's role
     * changed - silently, because a stale yes reads exactly like a fresh one.
     * And if the reload below ever failed to run, what would be found in the
     * session is nothing at all, so the way this breaks is by refusing rather
     * than by allowing.
     *
     * What is left is the account as it stood - the address, the name, the
     * preferences - which is drawn on the page and decides nothing.
     */
    public function sleepIdentity(IIdentity $identity): IIdentity
    {
        return $identity instanceof Identity ? $identity->holdingNothing() : $identity;
    }

    /**
     * Read at the start of every request: the whole set of roles this person
     * holds in the business this request is for.
     *
     * **The whole set, never a difference.** A right that was withdrawn and a
     * right that was never granted are the same absence, so anything that
     * merged changes into a set already in hand would have to tell them apart -
     * and it cannot, which is how a withdrawn right survives.
     *
     * **A different business is a reload and not a sign-out.** Decision D7: a
     * session carried to another host and a person who really does administer
     * two businesses look identical from here, so ending the session would
     * punish the second to inconvenience the first. Reading the set again gives
     * whoever it is exactly what they hold where they now are - which is
     * nothing at all unless they are a member - so there is nothing to be
     * gained by moving a session anywhere.
     *
     * **Before a business is settled, nobody holds anything.** The set is
     * emptied rather than kept or refused: it is not yet known whose request
     * this is, and the answer to "may they" until it is known is no.
     *
     * An identity this build did not write is signed out rather than carried
     * on with. Its roles were put there by something else, and a set nothing
     * here can vouch for is the one thing decision D3 cannot afford to trust.
     */
    public function wakeupIdentity(IIdentity $identity): ?IIdentity
    {
        return $identity instanceof Identity ? $this->holdingWhatIsHeldHere($identity) : null;
    }

    /**
     * The one place the set is read, so that decision D6's shared cache has one
     * place to stand in front of rather than two that would drift.
     *
     * The business it was read for is recorded on the identity beside the set.
     * Nothing consults that to decide whether to read again - today the reading
     * happens every request, so there is nothing it could save - and it is what
     * makes the set on an identity say what it is a set for.
     * **Exit condition:** decision D6, where it becomes half of the cache key
     * and the value a fingerprint is compared for.
     */
    private function holdingWhatIsHeldHere(Identity $identity): Identity
    {
        if (!$this->tenancy->isEntered()) {
            return $identity->holdingNothing();
        }

        $person = $identity->getId();

        return $identity->holdingRolesIn(
            $this->tenancy->current(),
            is_int($person) ? $this->memberships->rolesHeldBy($person) : [],
        );
    }
}
