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
 * is not, an account that has been switched off is not, and what comes back
 * carries the roles and permissions the account holds.
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
        self::assertSame(['administrator'], $identity->getRoles());
        self::assertSame(['administration'], $identity->permissions());
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
