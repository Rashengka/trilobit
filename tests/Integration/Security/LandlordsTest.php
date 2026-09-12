<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Security;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use Nette\Security\Authenticator;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Identity;
use Trilobit\Core\Security\Landlords;
use Trilobit\Core\Security\Permissions;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;
use Trilobit\Core\Tenancy\Tenancy;
use Trilobit\Core\Tenancy\TenancyRefused;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * Whether the person making this request administers the installation, against
 * a real database.
 *
 * Two claims, and the second is the one worth the suite.
 *
 * **It is a question of its own, asked without a tenant.** Every case here runs
 * in an installation where no business has been entered, which is where the
 * question is really asked: an account administering the installation is in no
 * business, so anything that had to be told which one first could not answer
 * for them at all.
 *
 * **The answer comes from the row and not from the session.** A snapshot on the
 * identity would keep saying yes until the person signed in again, so taking
 * the flag away would not take effect and nobody would notice, because a stale
 * yes looks exactly like a fresh one. It is asserted by taking the flag away
 * underneath a session that stays open.
 */
#[CoversNothing]
final class LandlordsTest extends TestCase
{
    private string $schema = '';

    private ?Container $container = null;

    /** @var array<string, string> by the address the account signs in with */
    private array $passwords = [];

    protected function tearDown(): void
    {
        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->container = null;
        $this->passwords = [];

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    /** Not signed in is answered rather than refused: the question is asked while drawing pages a visitor may see. */
    public function testNobodySignedInDoesNotAdministerTheInstallation(): void
    {
        $this->installation();

        self::assertFalse($this->landlords()->isLandlord());
    }

    public function testAnAccountMadeAsOneAdministersTheInstallation(): void
    {
        $this->installation();
        $this->signInAs('landlord@example.com');

        self::assertTrue($this->landlords()->isLandlord());
    }

    /**
     * And an ordinary account does not, which is what keeps the case above from
     * passing for a service that answers yes to everybody signed in.
     */
    public function testAnOrdinaryAccountDoesNot(): void
    {
        $this->installation();
        $this->signInAs('alice@example.com');

        self::assertFalse($this->landlords()->isLandlord());
    }

    /**
     * The flag is taken away while the session stays open, and the very next
     * reading says no.
     *
     * The row is changed through the mapping rather than through the object,
     * because there is deliberately no way to say it on
     * Trilobit\Core\Domain\User\User - what an account is, is settled when it is
     * made. That makes this the honest shape of the case anyway: somebody else
     * changed the row, and this process has to find out. Clearing the entity
     * manager afterwards is where one request ends and the next begins; what is
     * *not* cleared is the session, which is the whole point.
     */
    public function testTakingTheFlagAwayTakesEffectWithoutSigningInAgain(): void
    {
        $this->installation();
        $this->signInAs('landlord@example.com');

        self::assertTrue($this->landlords()->isLandlord());

        $entityManager = $this->container()->getByType(EntityManagerInterface::class);
        $entityManager
            ->createQuery(sprintf('UPDATE %s u SET u.landlord = false WHERE u.email = :email', User::class))
            ->setParameter('email', 'landlord@example.com')
            ->execute();
        $entityManager->clear();

        self::assertTrue($this->container()->getByType(SignedIn::class)->isLoggedIn(), 'nobody signed in again');
        self::assertFalse($this->landlords()->isLandlord());
    }

    /**
     * An account that administers the installation and holds a role in a
     * business as well is still answered yes - asked inside that very business.
     *
     * This is what keeps the answer from being worked out of "belongs to no
     * business". That reading gives the same answers as the flag for every
     * other case in this suite - the ordinary account above belongs to none
     * and is not one, which catches the reading in the opposite direction - and
     * it is wrong the moment one person is both, which a simple installation is
     * made of. It would be quiet too: the installation's administrator would
     * lose their section on the day they were given a shop, and the page they
     * met would be a refusal that looks like any other.
     */
    public function testAnAccountThatAlsoHoldsARoleInABusinessStillAdministersTheInstallation(): void
    {
        $this->installation();
        $business = Tenants::enter($this->container(), 'Ammonite Bikes');
        $this->alsoAMemberOf($business, 'landlord@example.com');

        $this->signInAs('landlord@example.com');

        self::assertTrue($this->landlords()->isLandlord());
    }

    /**
     * And being both gives it no way to be asked about outside a business.
     *
     * The account holds a role in one, and is signed in with nothing entered -
     * the process a command line or a request that has not been settled yet
     * is. What it may do is still a question with no answer there, however
     * much the account is: Trilobit\Core\Security\Permissions refuses rather
     * than answering with the rights it holds in some business or other, and
     * whether it administers the installation is still answered, because that
     * question never needed one.
     *
     * The membership is written by one build and the question asked of a
     * second over the same schema, because a process that has entered a
     * business has no way back out of it - which is the rule, not a gap in it.
     */
    public function testBeingBothAnswersNothingAboutPermissionsWithoutABusiness(): void
    {
        $this->installation();
        $this->alsoAMemberOf(Tenants::enter($this->container(), 'Ammonite Bikes'), 'landlord@example.com');

        $this->container()->getByType(SignedIn::class)->logout(true);
        $this->container = Boot::coreAlone();
        $this->signInAs('landlord@example.com');

        self::assertFalse($this->container()->getByType(Tenancy::class)->isEntered());
        self::assertTrue($this->landlords()->isLandlord());

        $this->expectException(TenancyRefused::class);
        $this->container()->getByType(Permissions::class)->isAllowed(Resource::Administration, Privilege::View);
    }

    /**
     * The same claim from the other side, and the one a reader can check by
     * looking: the identity put into the session carries no answer to this at
     * all, so there is nothing there for anybody to read instead.
     */
    public function testTheIdentityInTheSessionCarriesNoAnswerToIt(): void
    {
        $this->installation();

        $identity = $this->container()
            ->getByType(Authenticator::class)
            ->authenticate('landlord@example.com', $this->passwords['landlord@example.com'] ?? '');

        self::assertInstanceOf(Identity::class, $identity);
        self::assertArrayNotHasKey('landlord', $identity->getData());
        self::assertSame([], $identity->getRoles());
    }

    /**
     * An installation with one account of each kind and no business entered.
     *
     * Nothing tenanted is touched here on purpose: the account table is shared,
     * and an installation administrator has to be answerable about before any
     * business is settled.
     */
    private function installation(): void
    {
        $this->schema = Database::schemaFor(self::class);
        $this->container = Boot::coreAlone();
        Migrations::run($this->container);

        $this->account('alice@example.com', 'Alice Ammonite', landlord: false);
        $this->account('landlord@example.com', 'Bea Brachiopod', landlord: true);
    }

    /**
     * An account with a password generated here and kept only for the length of
     * the run. Nothing that could be signed in with is written down: the
     * repository is public, and a fixture password would be a disclosure git
     * keeps for ever.
     */
    private function account(string $email, string $name, bool $landlord): void
    {
        $password = Random::generate(24, 'a-zA-Z0-9');
        $this->passwords[$email] = $password;

        $this->container()->getByType(Accounts::class)->save(new User(
            $email,
            $this->container()->getByType(Passwords::class)->hash($password),
            $name,
            new DateTimeImmutable('2026-09-07T08:00:00+00:00'),
            landlord: $landlord,
        ));
    }

    /**
     * A role in $business for the account signing in as $email, given the one
     * way an account administering the installation can be given one.
     */
    private function alsoAMemberOf(Tenant $business, string $email): void
    {
        $account = $this->container()->getByType(Accounts::class)->withEmail($email);
        self::assertInstanceOf(User::class, $account);

        $entityManager = $this->container()->getByType(EntityManagerInterface::class);
        $role = new Role('owner', 'Owner', ['app:*']);
        $entityManager->persist($role);
        $entityManager->persist(Membership::forTheInstallationsAdministrator($business, $account, $role));
        $entityManager->flush();
    }

    private function signInAs(string $email): void
    {
        $this->container()->getByType(SignedIn::class)->login($email, $this->passwords[$email] ?? '');
    }

    private function landlords(): Landlords
    {
        return $this->container()->getByType(Landlords::class);
    }

    private function container(): Container
    {
        self::assertInstanceOf(Container::class, $this->container);

        return $this->container;
    }
}
