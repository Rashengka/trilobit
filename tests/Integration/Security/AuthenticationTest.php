<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Security;

use DateTimeImmutable;
use Nette\Security\AuthenticationException;
use Nette\Security\Authenticator as NetteAuthenticator;
use Nette\Security\Passwords;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Identity;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;

/**
 * Signing in, against a real database and a real password hash.
 *
 * The account this suite signs in as is made by the suite itself, and its
 * password is generated here and never written down. That is not tidiness: the
 * repository is public, so a password committed as a fixture would be a
 * disclosure git keeps forever - and one nobody could rotate, because every
 * checkout would carry it.
 *
 * What is asserted is the whole of what authentication has to get right: the
 * right password is accepted, a wrong one is not, an address nobody registered
 * is not, an account that has been switched off is not, and what comes back is
 * the person rather than anything they may do.
 *
 * **Nothing here enters a business, and that is what makes the last claim
 * measurable.** A role is held in one - see
 * Trilobit\Core\Domain\Tenancy\Membership - so an account with a role granted
 * on the account row itself holds nothing anywhere, and an identity made
 * outside every business has to say so. What somebody holding a role in a
 * business gets is asserted where a business exists, in
 * Trilobit\Tests\Integration\Security\AskingThroughNetteTest.
 */
#[CoversNothing]
final class AuthenticationTest extends TestCase
{
    private string $schema = '';

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            Database::drop($this->schema);
        }
    }

    public function testTheRightPasswordIsAccepted(): void
    {
        [$authenticator, $password] = $this->accountThatCanSignIn();

        $identity = $authenticator->authenticate('alice@example.com', $password);

        self::assertInstanceOf(Identity::class, $identity);
        self::assertSame('alice@example.com', $identity->email());
        self::assertSame('Alice Ammonite', $identity->displayName());
        self::assertSame(['administration'], $identity->permissions());
    }

    /**
     * The account was granted a role directly, the way core_user_role lets it
     * be, and the identity carries none - because that grant names no business
     * and a right that names no business would be a right in all of them.
     *
     * The other half of the same sentence is that nothing was read for a
     * business here either: no host settled one, so the set is empty rather
     * than stale, and it says which business it is for by saying none.
     */
    public function testARoleGrantedOnTheAccountItselfIsNotOneTheIdentityCarries(): void
    {
        [$authenticator, $password, $accounts] = $this->accountThatCanSignIn();

        $account = $accounts->withEmail('alice@example.com');
        self::assertInstanceOf(User::class, $account);
        self::assertSame(['administrator'], $account->roleCodes(), 'the grant this is about is really there');

        $identity = $authenticator->authenticate('alice@example.com', $password);

        self::assertInstanceOf(Identity::class, $identity);
        self::assertSame([], $identity->getRoles());
        self::assertNull($identity->rolesLoadedFor());
    }

    public function testAWrongPasswordIsRefused(): void
    {
        [$authenticator] = $this->accountThatCanSignIn();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionCode(NetteAuthenticator::InvalidCredential);

        $authenticator->authenticate('alice@example.com', 'not the one that was set');
    }

    public function testAnAddressNobodyRegisteredIsRefused(): void
    {
        [$authenticator, $password] = $this->accountThatCanSignIn();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionCode(NetteAuthenticator::IdentityNotFound);

        $authenticator->authenticate('nobody@example.com', $password);
    }

    /**
     * An account is switched off rather than deleted, so that what a person did
     * stays attributable - which is only worth anything if a switched-off
     * account cannot sign in.
     */
    public function testASwitchedOffAccountIsRefused(): void
    {
        [$authenticator, $password, $accounts] = $this->accountThatCanSignIn();

        $account = $accounts->withEmail('alice@example.com');
        self::assertInstanceOf(User::class, $account);
        $account->deactivate();
        $accounts->save($account);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionCode(NetteAuthenticator::NotApproved);

        $authenticator->authenticate('alice@example.com', $password);
    }

    public function testSigningInIsRecordedOnTheAccount(): void
    {
        [$authenticator, $password, $accounts] = $this->accountThatCanSignIn();

        $before = $accounts->withEmail('alice@example.com');
        self::assertInstanceOf(User::class, $before);
        self::assertNull($before->lastLoginAt());

        $authenticator->authenticate('alice@example.com', $password);

        $after = $accounts->withEmail('alice@example.com');
        self::assertInstanceOf(User::class, $after);
        self::assertNotNull($after->lastLoginAt());
    }

    /**
     * An account with a role and a generated password, and the password.
     *
     * @return array{NetteAuthenticator, string, Accounts}
     */
    private function accountThatCanSignIn(): array
    {
        $this->schema = Database::schemaFor(self::class);
        $container = Boot::coreAlone();
        Migrations::run($container);

        $accounts = $container->getByType(Accounts::class);
        $password = Random::generate(24, 'a-zA-Z0-9');

        $account = new User(
            'alice@example.com',
            $container->getByType(Passwords::class)->hash($password),
            'Alice Ammonite',
            new DateTimeImmutable('2026-09-04T08:00:00+00:00'),
        );
        $account->grant(new Role('administrator', 'Administrator', ['administration']));
        $accounts->save($account);

        return [$container->getByType(NetteAuthenticator::class), $password, $accounts];
    }
}
