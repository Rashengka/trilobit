<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use Nette\Security\Authorizator as NetteAuthorizator;
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
use Trilobit\Core\Security\Authenticator;
use Trilobit\Core\Security\Authorizator;
use Trilobit\Core\Security\Identity;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * The framework's own question - $user->isAllowed() - against a real database,
 * a real session and two businesses.
 *
 * This is the suite for the decision that we do not build a second way of
 * asking beside Nette's. Everything a page will be gated on goes through
 * Nette\Security\User, which loops over the roles on the identity and hands
 * each one to an authorizator; so the two things this has to prove are that the
 * roles on the identity are the ones held *here*, and that they are the ones
 * held *now*.
 *
 * **"Now" is the case worth the whole suite, and it is the quiet one.** A set of
 * roles copied into the session at sign-in works perfectly and goes on working
 * after the right behind it has been taken away - there is no error, no warning
 * and no difference to look at, only somebody still able to do a thing an
 * administrator withdrew. So it is asserted the only way it can be: the right is
 * taken away in one request and asked about in the next, with nobody signing in
 * in between.
 *
 * **A request is a new container over the same session.** Reloading identities
 * by hand would be measuring the reload rather than the thing that triggers it;
 * building the services again is what a second HTTP request really is, and the
 * session is the one thing deliberately not built again.
 */
#[CoversNothing]
final class AskingThroughNetteTest extends TestCase
{
    private const string EDITOR = 'content-editor';

    private const string OWNER = 'owner';

    /** Granted on the account itself rather than in a business, which is what must not answer. */
    private const string GRANTED_GLOBALLY = 'granted-globally';

    private string $schema = '';

    private ?Container $container = null;

    /** @var array<string, string> by the address the account signs in with */
    private array $passwords = [];

    private ?Tenant $bikes = null;

    private ?Tenant $books = null;

    protected function tearDown(): void
    {
        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->letGoOfTheConnection($this->container);
        $this->container = null;
        $this->bikes = null;
        $this->books = null;
        $this->passwords = [];

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testTheFrameworksOwnQuestionAnswersForARoleHeldHere(): void
    {
        $this->installation();
        $this->signInAs('alice@example.com');

        self::assertTrue($this->signedIn()->isAllowed(Resource::Content, Privilege::Edit));
    }

    /**
     * The case this slice exists for.
     *
     * The membership is deleted in one request and the same question is asked in
     * the next one, over the same session and without anybody signing in again.
     * A set of roles that was copied into the session would still be there and
     * would still answer yes.
     */
    public function testARightWithdrawnInOneRequestIsRefusedInTheNext(): void
    {
        $this->installation();
        $this->signInAs('alice@example.com');

        self::assertTrue($this->signedIn()->isAllowed(Resource::Content, Privilege::Edit));

        $this->withdrawEveryMembershipOf('alice@example.com');
        $this->nextRequestIn($this->bikes());

        self::assertTrue(
            $this->authorizator()->isAllowed(self::EDITOR, Resource::Content, Privilege::Edit),
            'somebody else still holds that role here, so what follows can only be about who holds it',
        );
        self::assertTrue($this->signedIn()->isLoggedIn(), 'nobody signed in again');
        self::assertSame([], $this->signedIn()->getRoles());
        self::assertFalse($this->signedIn()->isAllowed(Resource::Content, Privilege::Edit));
    }

    /**
     * A role is held in a business, so carrying it into another one is not a
     * lesser right, it is somebody else's. The session is not ended over it -
     * a person who really does belong to two businesses is indistinguishable
     * from a session that was moved, and the honest answer to both is the set
     * they hold where they are now.
     */
    public function testARoleHeldInOneBusinessAnswersNothingInAnother(): void
    {
        $this->installation();
        $this->signInAs('alice@example.com');

        self::assertTrue($this->signedIn()->isAllowed(Resource::Content, Privilege::Edit));

        $this->nextRequestIn($this->books());

        self::assertTrue(
            $this->authorizator()->isAllowed(self::EDITOR, Resource::Content, Privilege::Edit),
            'somebody holds that role in this business too, so what follows can only be about who holds it',
        );
        self::assertTrue($this->signedIn()->isLoggedIn(), 'moving is not a reason to be signed out');
        self::assertSame([], $this->signedIn()->getRoles());
        self::assertFalse($this->signedIn()->isAllowed(Resource::Content, Privilege::Edit));
    }

    /**
     * The same claim from the other side, and the reason the one above cannot
     * pass by accident: a build that answered no to everybody would pass it.
     * This account holds its role in the second business, so the answers have to
     * come back the opposite way round.
     */
    public function testSomebodyElseIsAllowedInTheirOwnBusinessAndNotInThisOne(): void
    {
        $this->installation();
        $this->signInAs('bob@example.com');

        self::assertFalse($this->signedIn()->isAllowed(Resource::Content, Privilege::Edit));

        $this->nextRequestIn($this->books());

        self::assertSame([self::OWNER], $this->signedIn()->getRoles());
        self::assertTrue($this->signedIn()->isAllowed(Resource::Content, Privilege::Edit));
    }

    /**
     * A role granted on the account itself is not a role held anywhere, and the
     * identity must not carry it. The account table is shared by the whole
     * installation, so a right read off it would be a right in every business
     * at once.
     */
    public function testARoleGrantedOnTheAccountItselfIsNotOneTheIdentityCarries(): void
    {
        $this->installation();
        $this->signInAs('alice@example.com');

        self::assertSame([self::EDITOR], $this->signedIn()->getRoles());
        self::assertFalse($this->signedIn()->isAllowed(Resource::Content, Privilege::Purge));
    }

    /**
     * Nette's own vocabulary for somebody who is not signed in - and it reaches
     * the authorizator, because User::getRoles() answers 'guest' rather than
     * nothing. Nette\Security\Permission::isAllowed() raises on a role it was
     * not given, so an authorizator passing that name straight through would
     * turn every public page into an error for every visitor.
     */
    public function testARoleNameTheAccessListDoesNotKnowIsAnsweredNoRatherThanRaising(): void
    {
        $this->installation();

        self::assertFalse($this->signedIn()->isLoggedIn());
        self::assertFalse($this->signedIn()->isAllowed(Resource::Content, Privilege::Edit));
        self::assertFalse($this->authorizator()->isAllowed('guest', Resource::Content, Privilege::Edit));
        self::assertFalse($this->authorizator()->isAllowed('authenticated', Resource::Content, Privilege::Edit));
    }

    /**
     * What is written into the session carries who somebody is and nothing
     * about what they may do.
     *
     * It is the half of the design that decides which way this breaks. If the
     * refresh above ever stopped running - a release of the framework that
     * moved the hook, a second authenticator registered beside this one - what
     * would be found in the session is an empty set, so the symptom is somebody
     * being refused rather than somebody keeping a right that was taken away.
     * A stored set would fail the other way and look like nothing at all.
     */
    public function testWhatGoesIntoTheSessionCarriesNoRights(): void
    {
        $this->installation();

        $authenticator = $this->container()->getByType(Authenticator::class);
        $identity = $authenticator->authenticate('alice@example.com', $this->passwords['alice@example.com'] ?? '');

        self::assertInstanceOf(Identity::class, $identity);
        self::assertSame([self::EDITOR], $identity->getRoles());
        self::assertSame($this->bikes()->id(), $identity->rolesLoadedFor(), 'a set says which business it is for');

        $stored = $authenticator->sleepIdentity($identity);

        self::assertInstanceOf(Identity::class, $stored);
        self::assertSame([], $stored->getRoles());
        self::assertNull($stored->rolesLoadedFor());
        self::assertSame('alice@example.com', $stored->email(), 'who they are still goes in');
        self::assertSame([self::EDITOR], $identity->getRoles(), 'the identity in hand was not emptied as well');
    }

    /**
     * A rule written about the administration does not answer for a section
     * of it, through this route as much as through
     * Trilobit\Core\Security\Permissions: the access list an authorizator is
     * built on is put together by the same
     * Trilobit\Core\Security\AccessComposition, out of the same
     * src/Core/Security/permissions.neon. The role opens the administration
     * and edits the content, so the no is about reading the content and not
     * about the role answering nothing.
     */
    public function testARuleOnTheAdministrationDoesNotAnswerForASectionOfIt(): void
    {
        $this->installation();
        $this->signInAs('alice@example.com');

        self::assertTrue($this->signedIn()->isAllowed(Resource::Administration, Privilege::View));
        self::assertTrue($this->signedIn()->isAllowed(Resource::Content, Privilege::Edit));
        self::assertFalse($this->signedIn()->isAllowed(Resource::Content, Privilege::View));
    }

    /**
     * A business, a second business, and four accounts arranged so that each
     * case below can only pass for its own reason.
     *
     * **Two people hold the editor's role in each business, and only one of them
     * ever signs in.** Without the other, taking the signed-in one's membership
     * away would also take the last holder of that role out of the business -
     * and the access list, which is built out of the roles somebody holds here,
     * would refuse the question on its own. The case would go green while
     * proving nothing about the set on the identity, which is the only thing it
     * is there to prove. The same reasoning puts a holder of the editor's role
     * in the second business: a name that means nothing there would be refused
     * for being unknown rather than for not being held by this person.
     *
     * Alice is also granted a role on the account itself. That is deliberate and
     * has to stay: it is the shape a right that is not held in any business
     * takes, and it is what the identity must be shown not to carry.
     */
    private function installation(): void
    {
        $this->schema = Database::schemaFor(self::class);
        $this->container = Boot::coreAlone();
        Migrations::run($this->container);

        $this->bikes = Tenants::enter($this->container, 'Ammonite Bikes');
        $this->books = Tenants::create($this->container, 'Trilobite Books');

        $accounts = $this->container->getByType(Accounts::class);
        $alice = $this->account($accounts, 'alice@example.com', 'Alice Ammonite');
        $bob = $this->account($accounts, 'bob@example.com', 'Bob Belemnite');
        $carol = $this->account($accounts, 'carol@example.com', 'Carol Crinoid');
        $dave = $this->account($accounts, 'dave@example.com', 'Dave Dinocaris');

        $entityManager = $this->container->getByType(EntityManagerInterface::class);
        $editor = new Role(self::EDITOR, 'Content editor', ['app.administration:view', 'app.administration.content:edit']);
        $owner = new Role(self::OWNER, 'Owner', ['app.administration:view', 'app.administration.content:edit']);
        $entityManager->persist($editor);
        $entityManager->persist($owner);
        $entityManager->persist(new Membership($this->bikes, $alice, $editor));
        $entityManager->persist(new Membership($this->bikes, $carol, $editor));
        $entityManager->persist(new Membership($this->books, $bob, $owner));
        $entityManager->persist(new Membership($this->books, $dave, $editor));
        $entityManager->flush();

        $alice->grant(new Role(self::GRANTED_GLOBALLY, 'Granted globally', ['app.administration.content:purge']));
        $accounts->save($alice);
    }

    /**
     * An account with a password generated here and kept only for the length of
     * the run. Nothing that could be signed in with is written down: the
     * repository is public, and a fixture password would be a disclosure git
     * keeps for ever.
     */
    private function account(Accounts $accounts, string $email, string $name): User
    {
        $password = Random::generate(24, 'a-zA-Z0-9');
        $this->passwords[$email] = $password;

        $account = new User(
            $email,
            $this->container()->getByType(Passwords::class)->hash($password),
            $name,
            new DateTimeImmutable('2026-09-08T08:00:00+00:00'),
        );
        $accounts->save($account);

        return $account;
    }

    /**
     * What an administrator taking a right away really does, said in the
     * language of the table rather than of an entity, because there is no screen
     * to do it through yet and a screen would be a second thing being asserted.
     */
    private function withdrawEveryMembershipOf(string $email): void
    {
        $this->container()
            ->getByType(EntityManagerInterface::class)
            ->createQuery(sprintf(
                'DELETE FROM %s m WHERE m.user IN (SELECT u.id FROM %s u WHERE u.email = :email)',
                Membership::class,
                User::class,
            ))
            ->setParameter('email', $email)
            ->execute();
    }

    /**
     * The next request: the services are built again and the session is not.
     *
     * The tenant is settled first, exactly as Trilobit\Core\Tenancy\TenantFromHost
     * settles it from the host before anything is routed - a request that read
     * the identity first would read it before it was known whose request this is.
     */
    private function nextRequestIn(Tenant $tenant): void
    {
        $this->letGoOfTheConnection($this->container);

        $this->container = Boot::coreAlone();
        Tenants::switchTo($this->container, $tenant);
    }

    /**
     * The end of a request, in the one respect a test process has to be told
     * about: a container that is finished with lets go of its database
     * connection.
     *
     * A real request ends when the process does. Here the process outlives
     * every request in the file, so a connection left open is one held until
     * the whole suite finishes - and a server has a few hundred of them for
     * every suite running at once.
     */
    private function letGoOfTheConnection(?Container $container): void
    {
        $container?->getByType(Connection::class)->close();
    }

    private function signInAs(string $email): void
    {
        $this->signedIn()->login($email, $this->passwords[$email] ?? '');
    }

    private function signedIn(): SignedIn
    {
        return $this->container()->getByType(SignedIn::class);
    }

    /**
     * Asked for by the framework's type and found to be ours, which is the
     * whole of the wiring: Nette\Security\User takes an authorizator that way
     * and there is no second place to register one.
     */
    private function authorizator(): Authorizator
    {
        $authorizator = $this->container()->getByType(NetteAuthorizator::class);
        self::assertInstanceOf(Authorizator::class, $authorizator);

        return $authorizator;
    }

    private function bikes(): Tenant
    {
        self::assertInstanceOf(Tenant::class, $this->bikes);

        return $this->bikes;
    }

    private function books(): Tenant
    {
        self::assertInstanceOf(Tenant::class, $this->books);

        return $this->books;
    }

    private function container(): Container
    {
        self::assertInstanceOf(Container::class, $this->container);

        return $this->container;
    }
}
