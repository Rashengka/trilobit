<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use Nette\Security\AuthenticationException;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Authenticator;
use Trilobit\Core\Security\PasswordLinks;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * The link somebody added to a business sets their password with.
 *
 * Everything here is about the link being a key: whoever holds it may set the
 * password of an account they have never signed in to. So it is kept only as a
 * hash, it lasts a week, it opens once, a newer one takes the older one's
 * place - and every link that will not open is refused in the same words, so
 * that trying one says nothing about whether it ever existed.
 */
#[CoversNothing]
final class PasswordLinksTest extends TestCase
{
    private string $schema = '';

    private ?Container $container = null;

    protected function tearDown(): void
    {
        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->container = null;

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testWhatIsKeptIsAHashOfTheTokenAndNeverTheToken(): void
    {
        $account = $this->invited('ivo@example.com');
        $token = $this->links()->issue($account);

        $row = $this->connection()->fetchAssociative('SELECT * FROM core_password_link');
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $token), $row['token_hash']);
        foreach ($row as $column => $value) {
            self::assertStringNotContainsString($token, is_scalar($value) ? (string) $value : '', $column . ' holds the token itself');
        }
    }

    public function testATokenIsLongEnoughNotToBeGuessedAndFitsAnAddress(): void
    {
        $token = $this->links()->issue($this->invited('ivo@example.com'));

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43,}$/', $token);
    }

    public function testALinkLastsSevenDays(): void
    {
        $this->links()->issue($this->invited('ivo@example.com'));

        $row = $this->connection()->fetchAssociative('SELECT created_at, expires_at FROM core_password_link');
        self::assertIsArray($row);
        self::assertIsString($row['created_at']);
        self::assertIsString($row['expires_at']);
        $created = new DateTimeImmutable($row['created_at']);
        $expires = new DateTimeImmutable($row['expires_at']);

        self::assertSame(7 * 24 * 3600, $expires->getTimestamp() - $created->getTimestamp());
        self::assertSame(7, PasswordLinks::DAYS);
    }

    public function testTheLinkSetsThePasswordAndOpensOnce(): void
    {
        $account = $this->invited('ivo@example.com');
        $token = $this->links()->issue($account);

        self::assertSame('ivo@example.com', $this->links()->holderOf($token)?->email());

        $password = Random::generate(20);
        $set = $this->links()->spend($token, $this->passwords()->hash($password));
        self::assertSame('ivo@example.com', $set?->email());

        $this->entityManager()->clear();
        $reread = $this->container()->getByType(Accounts::class)->withEmail('ivo@example.com');
        self::assertInstanceOf(User::class, $reread);
        self::assertTrue($this->passwords()->verify($password, $reread->passwordHash()));

        self::assertNull($this->links()->holderOf($token), 'a used link still opens');
        self::assertNull($this->links()->spend($token, $this->passwords()->hash(Random::generate(20))), 'a used link set a password again');
    }

    public function testANewerLinkTakesTheOlderOnesPlace(): void
    {
        $account = $this->invited('ivo@example.com');
        $older = $this->links()->issue($account);
        $newer = $this->links()->issue($account);

        self::assertNull($this->links()->holderOf($older));
        self::assertNull($this->links()->spend($older, $this->passwords()->hash(Random::generate(20))));
        self::assertSame('ivo@example.com', $this->links()->holderOf($newer)?->email());
    }

    /** Only the same account's: a link sent to somebody else stays theirs. */
    public function testANewerLinkLeavesAnotherAccountsAlone(): void
    {
        $ivo = $this->links()->issue($this->invited('ivo@example.com'));
        $this->links()->issue($this->invited('uma@example.com'));

        self::assertSame('ivo@example.com', $this->links()->holderOf($ivo)?->email());
    }

    public function testALinkPastItsWeekIsRefused(): void
    {
        $token = $this->links()->issue($this->invited('ivo@example.com'));
        $this->connection()->executeStatement(
            'UPDATE core_password_link SET expires_at = ?',
            [new DateTimeImmutable('-1 minute')->format('Y-m-d H:i:s')],
        );

        self::assertNull($this->links()->holderOf($token));
        self::assertNull($this->links()->spend($token, $this->passwords()->hash(Random::generate(20))));
    }

    public function testATokenNobodyWasGivenIsRefused(): void
    {
        $this->links()->issue($this->invited('ivo@example.com'));

        self::assertNull($this->links()->holderOf(Random::generate(43, 'A-Za-z0-9_-')));
        self::assertNull($this->links()->holderOf(''));
    }

    /**
     * An account added by invitation has no password until the link sets one,
     * and until then it cannot be signed in to - refused in the words every
     * refusal is, so that the sign-in page does not tell anybody which
     * addresses are waiting for their link.
     */
    public function testAnAccountWaitingForItsLinkCannotBeSignedInTo(): void
    {
        $this->invited('ivo@example.com');

        try {
            $this->container()->getByType(SignedIn::class)->login('ivo@example.com', '');
            self::fail('an account with no password was signed in to with an empty one');
        } catch (AuthenticationException $refused) {
            self::assertSame(Authenticator::REFUSAL, $refused->getMessage());
        }

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage(Authenticator::REFUSAL);
        $this->container()->getByType(SignedIn::class)->login('ivo@example.com', Random::generate(20));
    }

    public function testAnInvitedAccountSaysItHasNoPasswordYet(): void
    {
        $account = $this->invited('ivo@example.com');
        self::assertFalse($account->hasPassword());

        $this->links()->spend($this->links()->issue($account), $this->passwords()->hash(Random::generate(20)));
        self::assertTrue($account->hasPassword());
    }

    private function invited(string $email): User
    {
        $account = User::invited($email, 'Ivo Isopod', new DateTimeImmutable());
        $this->container()->getByType(Accounts::class)->save($account);

        return $account;
    }

    private function links(): PasswordLinks
    {
        return $this->container()->getByType(PasswordLinks::class);
    }

    private function passwords(): Passwords
    {
        return $this->container()->getByType(Passwords::class);
    }

    private function connection(): Connection
    {
        return $this->container()->getByType(Connection::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->container()->getByType(EntityManagerInterface::class);
    }

    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $this->container = Boot::coreAlone();
        Migrations::run($this->container);
        // Signing in reads the roles held in the business the request is in.
        Tenants::enter($this->container, 'Ammonite Bikes');

        return $this->container;
    }
}
