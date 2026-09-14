<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Trilobit\Core\Domain\User\PasswordLink;
use Trilobit\Core\Domain\User\User;

/**
 * The links a password is set with: made for an account, asked about, and
 * spent.
 *
 * **Whether a link opens is one condition, asked of the database every time**
 * - not used, not replaced by a newer one, not past its week - and it is
 * written once, in OPENS, for both questions this class answers. Asked of an
 * entity instead, the answer would be whatever the entity manager happened to
 * be holding: a link spent a moment ago, read back from memory, would still
 * look unused.
 *
 * **Spending it is one statement that either wins or does not.** The link is
 * marked used on the condition that it still opens, and only the request whose
 * statement changed the row goes on to set the password. Two requests posting
 * the same link at once cannot both set one, and neither of them has to lock
 * anything to know which of them it was.
 *
 * **Every link that does not open is refused the same way** - null, whether it
 * was never given, used, replaced or expired. Which of them it was is exactly
 * what somebody guessing at links would like to learn, and what the person
 * holding a stale one needs is the same in every case: a new link.
 */
final readonly class PasswordLinks
{
    /** How long a link opens for. */
    public const int DAYS = 7;

    /**
     * How many random bytes a token is made of - 256 bits, which nobody
     * guesses, and 43 characters once written into an address.
     */
    private const int BYTES = 32;

    /** Whether a link opens, as the one condition both questions ask; see the class. */
    private const string OPENS = 'l.digest = :digest AND l.usedAt IS NULL AND l.supersededAt IS NULL AND l.expiresAt > :now';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * A new link for $account, and the token to send it with - the only time
     * the token exists outside the message it goes into. Every link $account
     * still had stops opening.
     */
    public function issue(User $account): string
    {
        $now = new DateTimeImmutable();
        $token = $this->encoded(random_bytes(self::BYTES));

        $this->entityManager->wrapInTransaction(function () use ($account, $now, $token): void {
            $this->entityManager
                ->createQuery(sprintf(
                    'UPDATE %s l SET l.supersededAt = :now WHERE l.account = :account AND l.usedAt IS NULL AND l.supersededAt IS NULL',
                    PasswordLink::class,
                ))
                ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
                ->setParameter('account', $account)
                ->execute();

            $this->entityManager->persist(new PasswordLink(
                $account,
                $this->digestOf($token),
                $now,
                $now->modify(sprintf('+%d days', self::DAYS)),
            ));
            $this->entityManager->flush();
        });

        return $token;
    }

    /** The account $token sets the password of, or null when it does not open - for any reason. */
    public function holderOf(string $token): ?User
    {
        $link = $this->entityManager
            ->createQuery(sprintf('SELECT l, a FROM %s l JOIN l.account a WHERE %s', PasswordLink::class, self::OPENS))
            ->setParameter('digest', $this->digestOf($token))
            ->setParameter('now', new DateTimeImmutable(), Types::DATETIME_IMMUTABLE)
            ->getOneOrNullResult();

        return $link instanceof PasswordLink ? $link->account() : null;
    }

    /**
     * Sets the password of the account $token belongs to, and spends the
     * token. Null, and nothing changed, when it does not open - including
     * when another request spent it a moment before this one.
     *
     * @param string $passwordHash the new password, already hashed
     */
    public function spend(string $token, string $passwordHash): ?User
    {
        $digest = $this->digestOf($token);

        return $this->entityManager->wrapInTransaction(function () use ($digest, $passwordHash): ?User {
            $spent = $this->entityManager
                ->createQuery(sprintf('UPDATE %s l SET l.usedAt = :now WHERE %s', PasswordLink::class, self::OPENS))
                ->setParameter('digest', $digest)
                ->setParameter('now', new DateTimeImmutable(), Types::DATETIME_IMMUTABLE)
                ->execute();

            if ($spent !== 1) {
                return null;
            }

            $link = $this->entityManager->getRepository(PasswordLink::class)->findOneBy(['digest' => $digest]);
            if (!$link instanceof PasswordLink) {
                return null;
            }

            $account = $link->account();
            $account->changePassword($passwordHash);
            $this->entityManager->flush();

            return $account;
        });
    }

    private function digestOf(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Base64 as an address may carry it: `-` and `_`, and no padding. */
    private function encoded(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
