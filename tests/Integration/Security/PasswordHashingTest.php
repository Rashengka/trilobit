<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Security;

use DateTimeImmutable;
use Nette\DI\Container;
use Nette\Security\Authenticator as NetteAuthenticator;
use Nette\Security\Passwords;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Identity;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;

/**
 * What this installation turns a password into, and what happens to the ones it
 * turned into something else before.
 *
 * The algorithm is a decision rather than a default, and the decision is about
 * one number. bcrypt reads the first 72 bytes of what it is given and ignores
 * the rest without saying so, so two visibly different passphrases sharing a
 * first 72 bytes are one and the same as far as it is concerned - both sign in,
 * and the transcript of the wrong one is the transcript of the right one. That
 * is the shape this project treats as the dangerous kind of bug - success and
 * failure produced the same output - and it cannot be fixed by being careful,
 * because being careful would have to happen at every place a password is ever
 * set. argon2id truncates nothing, so the decision stops existing.
 *
 * **The passphrase below is longer than 72 bytes on purpose and is nobody's.**
 * It is generated where a real one is needed and made of invented words where a
 * fixed one is; the repository is public, so a password written into a fixture
 * would be a disclosure git keeps forever.
 *
 * The second half of the suite is the half that could go wrong quietly.
 * Changing the algorithm does not rewrite the hashes already in the database,
 * and an installation whose accounts were made last week has bcrypt in every
 * row. Those accounts have to go on signing in, and they have to stop being
 * truncatable at the first opportunity - which is the one moment the password
 * is in hand, on the way through Trilobit\Core\Security\Authenticator. That is
 * asserted end to end here rather than assumed from needsRehash() returning
 * true.
 */
#[CoversNothing]
final class PasswordHashingTest extends TestCase
{
    /**
     * Eight invented words, which come to 81 characters - the point being that
     * everything past the 72nd is what bcrypt was throwing away.
     *
     * Joined at run time rather than written as one literal, because a string
     * that long reads to bin/check-leaks exactly like a key somebody pasted in,
     * and it is right to: the way to keep the guard sharp is to give it nothing
     * to be wrong about.
     *
     * @var list<string>
     */
    private const array LONG_PASSPHRASE = [
        'ammonite', 'trilobite', 'graptolite', 'brachiopod',
        'crinoid', 'stromatolite', 'belemnite', 'nautiloid',
    ];

    private const int WHERE_BCRYPT_STOPPED_READING = 72;

    private string $schema = '';

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            Database::drop($this->schema);
        }
    }

    public function testTheInstallationHashesWithArgon2id(): void
    {
        $hash = $this->passwords()->hash($this->longPassphrase());

        self::assertSame('argon2id', password_get_info($hash)['algoName']);
    }

    /**
     * The claim the change was made for. Both halves matter: the truncated
     * passphrase is refused, and so is one that shares those 72 bytes and then
     * says something else entirely - the second is the one that made two
     * passwords into one.
     */
    public function testAPassphraseIsNotTruncated(): void
    {
        $passwords = $this->passwords();
        $hash = $passwords->hash($this->longPassphrase());

        self::assertTrue(
            $passwords->verify($this->longPassphrase(), $hash),
            'the passphrase that was hashed has to verify against its own hash',
        );
        self::assertFalse(
            $passwords->verify($this->truncated(), $hash),
            'a passphrase cut at 72 bytes verified, so something is still reading only that far',
        );
        self::assertFalse(
            $passwords->verify($this->truncatedWithAnotherTail(), $hash),
            'a passphrase sharing the first 72 bytes and nothing else verified, so two passwords are one',
        );
    }

    /**
     * An account made before the change still signs in, and the algorithm its
     * hash was written with is what says so - a suite that only asserted "the
     * right password is accepted" would pass just as well if bcrypt had never
     * been in the database at all.
     */
    public function testAnAccountWhoseHashIsBcryptStillSignsIn(): void
    {
        $password = Random::generate(24, 'a-zA-Z0-9');
        [$container, $accounts] = $this->installationHolding('alice@example.com', Passwords::bcrypt()->hash($password));

        $stored = $accounts->withEmail('alice@example.com');
        self::assertInstanceOf(User::class, $stored);
        self::assertSame(
            'bcrypt',
            password_get_info($stored->passwordHash())['algoName'],
            'the account this test is about has to start out with a bcrypt hash',
        );

        $identity = $container->getByType(NetteAuthenticator::class)->authenticate('alice@example.com', $password);

        self::assertInstanceOf(Identity::class, $identity);
        self::assertSame('alice@example.com', $identity->email());
    }

    /**
     * Signing in is where a bcrypt hash becomes an argon2id one, and after that
     * the account is no longer truncatable. Asserted on the row that was
     * written rather than on the identity that came back, because it is the row
     * the next sign-in reads.
     */
    public function testSigningInCarriesABcryptHashOverToArgon2id(): void
    {
        [$container, $accounts] = $this->installationHolding(
            'bob@example.com',
            Passwords::bcrypt()->hash($this->longPassphrase()),
        );

        $container->getByType(NetteAuthenticator::class)->authenticate('bob@example.com', $this->longPassphrase());

        $carried = $accounts->withEmail('bob@example.com');
        self::assertInstanceOf(User::class, $carried);
        self::assertSame(
            'argon2id',
            password_get_info($carried->passwordHash())['algoName'],
            'the hash was not replaced on the way past, so this account keeps bcrypt for ever',
        );

        $passwords = $container->getByType(Passwords::class);
        self::assertTrue(
            $passwords->verify($this->longPassphrase(), $carried->passwordHash()),
            'the password that signed in stopped working once its hash was replaced',
        );
        self::assertFalse(
            $passwords->verify($this->truncatedWithAnotherTail(), $carried->passwordHash()),
            'the account carried over and is still truncatable, so the carry-over bought nothing',
        );
    }

    /**
     * The other half of the same mechanism, and the half that decides whether
     * it ever runs: an argon2id hash must not be reported as out of date, or
     * every sign-in would rewrite a row that was already right.
     */
    public function testAHashThisInstallationJustWroteIsNotOutOfDate(): void
    {
        $passwords = $this->passwords();

        self::assertTrue(
            $passwords->needsRehash(Passwords::bcrypt()->hash($this->longPassphrase())),
            'a bcrypt hash is not reported as out of date, so nothing would ever carry it over',
        );
        self::assertFalse(
            $passwords->needsRehash($passwords->hash($this->longPassphrase())),
            'a hash this installation just wrote is reported as out of date, so every sign-in rewrites it',
        );
    }

    private function longPassphrase(): string
    {
        return implode('-', self::LONG_PASSPHRASE);
    }

    private function truncated(): string
    {
        return substr($this->longPassphrase(), 0, self::WHERE_BCRYPT_STOPPED_READING);
    }

    private function truncatedWithAnotherTail(): string
    {
        return $this->truncated() . 'XXXXXXXX';
    }

    private function passwords(): Passwords
    {
        return Boot::coreAlone()->getByType(Passwords::class);
    }

    /**
     * An installation with one account in it whose hash was written by
     * something other than this build.
     *
     * @return array{Container, Accounts}
     */
    private function installationHolding(string $email, string $hash): array
    {
        $this->schema = Database::schemaFor(self::class);
        $container = Boot::coreAlone();
        Migrations::run($container);

        $accounts = $container->getByType(Accounts::class);
        $accounts->save(new User(
            $email,
            $hash,
            'Somebody Invented',
            new DateTimeImmutable('2026-09-04T08:00:00+00:00'),
        ));

        return [$container, $accounts];
    }
}
