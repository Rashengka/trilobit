<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Security;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
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
use Trilobit\Core\Security\Permissions;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * What somebody may do, against a real database, in two tenants at once.
 *
 * The claim being measured is the one this whole design exists for: the same
 * account, signed in once, holding a role in one business and none in the
 * other, has to be told no in the second - and told it without anybody having
 * written the tenant into the question. It is asserted by asking the same
 * question twice with nothing changed but which tenant the process is in.
 *
 * That failure is the quiet kind. Nothing throws when a permission leaks
 * across a tenant; the page opens, and it opens for somebody who was never
 * given anything in that business. So the two halves are asserted separately:
 * that the right person is allowed, and that the same call answers no when the
 * process has moved.
 */
#[CoversNothing]
final class PermissionsInATenantTest extends TestCase
{
    private const string EDITOR = 'content-editor';

    private const string ADMINISTRATOR = 'administrator';

    private string $schema = '';

    private ?Container $container = null;

    /** @var array<string, string> by the address the account signs in with */
    private array $passwords = [];

    private ?Tenant $bikes = null;

    private ?Tenant $books = null;

    /** The first business's own role, the one the first account holds there. */
    private ?Role $editor = null;

    protected function tearDown(): void
    {
        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->container = null;
        $this->bikes = null;
        $this->books = null;
        $this->editor = null;
        $this->passwords = [];

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testARoleHeldInThisTenantAnswersForIt(): void
    {
        $this->installation();
        $this->signIn();

        self::assertTrue($this->permissions()->isAllowed(Resource::Content, Privilege::Edit));
    }

    /**
     * The same account, the same session, the same line of code - and no,
     * because a role is held in a tenant and this is a different one. Nothing
     * about the question says which tenant it is about, which is the point:
     * there was nothing for anybody to forget to write.
     */
    public function testTheSameQuestionIsAnsweredNoInATenantWhereNoRoleIsHeld(): void
    {
        $this->installation();
        $this->signIn();

        self::assertTrue($this->permissions()->isAllowed(Resource::Content, Privilege::Edit));

        Tenants::switchTo($this->container(), $this->tenantWithoutAnyRole());

        self::assertFalse($this->permissions()->isAllowed(Resource::Content, Privilege::Edit));
    }

    /**
     * The same claim from the other side, and the reason the one above cannot
     * pass by accident.
     *
     * A service that ignored tenancy altogether would answer yes in both, and
     * one that answered no to everything would answer no in both; the second
     * mistake is the easier one to ship, because the test for the first is the
     * one everybody writes. So the second person is put the other way round -
     * a role in the second tenant and none in the first - and the same
     * question has to come back the opposite way for them.
     */
    public function testSomebodyElseIsAllowedInTheirOwnTenantAndNotInThisOne(): void
    {
        $this->installation();
        $this->signInAs('bob@example.com');

        self::assertFalse($this->permissions()->isAllowed(Resource::Content, Privilege::Edit));

        Tenants::switchTo($this->container(), $this->tenantWithoutAnyRole());

        self::assertTrue($this->permissions()->isAllowed(Resource::Content, Privilege::Edit));
    }

    /**
     * A rule written about the administration does not answer for a section
     * of it: a pair means that pair, and opening the administration is not
     * reading what is in it. The role opens the administration and edits the
     * content, so the no below is about reading the content and nothing else.
     */
    public function testARuleOnTheAdministrationDoesNotAnswerForASectionOfIt(): void
    {
        $this->installation();
        $this->signIn();

        self::assertTrue($this->permissions()->isAllowed(Resource::Administration, Privilege::View));
        self::assertTrue($this->permissions()->isAllowed(Resource::Content, Privilege::Edit));
        self::assertFalse($this->permissions()->isAllowed(Resource::Content, Privilege::View));
    }

    /**
     * The other direction, and the one the structure's parents are for now:
     * any right in a section opens the administration it is a section of,
     * because Trilobit\Core\Security\AccessComposition works out `view` on
     * everything above it into a rule of its own.
     */
    public function testAPieceOnASectionOpensTheAdministration(): void
    {
        $this->installation(['app.administration.content:edit']);
        $this->signIn();

        self::assertTrue($this->permissions()->isAllowed(Resource::Administration, Privilege::View));
        self::assertFalse($this->permissions()->isAllowed(Resource::Content, Privilege::View));
    }

    public function testNobodySignedInIsAllowedNothing(): void
    {
        $this->installation();

        self::assertFalse($this->permissions()->isAllowed(Resource::Content, Privilege::Edit));
    }

    /**
     * A role may name a piece this build no longer has - it was written by an
     * earlier one. Nette raises on a resource it does not know, so carrying
     * that name as far as the access list would not deny this person one
     * thing, it would stop them using the application at all. It is left out
     * instead, and everything else the role names still holds.
     */
    public function testAPieceNamingSomethingThisBuildNoLongerHasIsLeftOut(): void
    {
        $this->installation(['invoicing:view', 'app.administration.content:edit', 'app.administration.content:apostille']);
        $this->signIn();

        self::assertTrue($this->permissions()->isAllowed(Resource::Content, Privilege::Edit));
        self::assertFalse($this->permissions()->isAllowed(Resource::Content, Privilege::Delete));
    }

    /**
     * An account that administers the installation and holds a role in one
     * business is asked about as a member of that business and nothing more:
     * allowed what the role there allows, and told no in the business where it
     * holds nothing - the same answers an ordinary account holding the same
     * role gets.
     *
     * The half worth the case is the second. Administering the installation is
     * the wider scope, so the tempting reading is that it opens every business
     * a little; it opens none, because a right is held in a business and the
     * flag is not held in any.
     */
    public function testAnAccountThatAlsoAdministersTheInstallationIsAskedAsAMemberAndOnlyWhereItIsOne(): void
    {
        $this->installation();

        $accounts = $this->container()->getByType(Accounts::class);
        $cora = $this->account($accounts, 'cora@example.com', 'Cora Crinoid', landlord: true);
        $entityManager = $this->container()->getByType(EntityManagerInterface::class);
        self::assertInstanceOf(Role::class, $this->editor);
        $entityManager->persist(Membership::forTheInstallationsAdministrator($this->business(), $cora, $this->editor));
        $entityManager->flush();

        $this->signInAs('cora@example.com');

        self::assertTrue($this->permissions()->isAllowed(Resource::Content, Privilege::Edit));
        self::assertFalse($this->permissions()->isAllowed(Resource::Content, Privilege::Delete));

        Tenants::switchTo($this->container(), $this->tenantWithoutAnyRole());

        self::assertFalse($this->permissions()->isAllowed(Resource::Content, Privilege::Edit));
        self::assertFalse($this->permissions()->isAllowed(Resource::Administration, Privilege::View));
    }

    /**
     * Two businesses each compose a role under the same name, and each means
     * something else by it: here the first one's editor edits and the second
     * one's deletes. Somebody holding the first one's is allowed what it
     * allows at home, and nothing in the second business - not even what the
     * second business's role of that name allows, although the name is the
     * name the access list there is keyed by.
     */
    public function testARoleOfTheSameNameInAnotherBusinessAnswersNothingForSomebodyHoldingTheirOwn(): void
    {
        $this->installation();
        $this->theSecondBusinessComposesAnEditorOfItsOwn();
        $this->signIn();

        self::assertTrue($this->permissions()->isAllowed(Resource::Content, Privilege::Edit));
        self::assertFalse($this->permissions()->isAllowed(Resource::Content, Privilege::Delete));

        Tenants::switchTo($this->container(), $this->tenantWithoutAnyRole());

        self::assertFalse($this->permissions()->isAllowed(Resource::Content, Privilege::Edit));
        self::assertFalse($this->permissions()->isAllowed(Resource::Content, Privilege::Delete));
    }

    /**
     * The same from the second business's side, so that the no above cannot be
     * a role that answers nothing anywhere: the second business's editor
     * deletes there and neither deletes nor edits in the first.
     */
    public function testTheSecondBusinessesRoleOfThatNameAnswersForItsHolderThereAndNowhereElse(): void
    {
        $this->installation();
        $this->theSecondBusinessComposesAnEditorOfItsOwn();
        $this->signInAs('dora@example.com');

        self::assertFalse($this->permissions()->isAllowed(Resource::Content, Privilege::Edit));
        self::assertFalse($this->permissions()->isAllowed(Resource::Content, Privilege::Delete));

        Tenants::switchTo($this->container(), $this->tenantWithoutAnyRole());

        self::assertTrue($this->permissions()->isAllowed(Resource::Content, Privilege::Delete));
        self::assertFalse($this->permissions()->isAllowed(Resource::Content, Privilege::Edit));
    }

    /**
     * A membership naming another business's role cannot be made - see
     * Trilobit\Core\Domain\Tenancy\Membership - so it is written here past the
     * object, the way a hand-edited row or a writer nobody has written yet
     * would. It still grants nothing: reading a role in this business reads the
     * application's and this business's own, and the second business's
     * administrator is neither.
     *
     * The row is counted first, so that the no is the row being passed over
     * rather than the row not being there.
     */
    public function testAMembershipNamingAnotherBusinesssRoleGrantsNothing(): void
    {
        $this->installation();

        $accounts = $this->container()->getByType(Accounts::class);
        $eve = $this->account($accounts, 'eve@example.com', 'Eve Eurypterid');
        $connection = $this->container()->getByType(EntityManagerInterface::class)->getConnection();
        $foreign = $connection->fetchOne('SELECT id FROM core_role WHERE code = ?', [self::ADMINISTRATOR]);
        $connection->insert('core_tenant_membership', [
            'tenant_id' => $this->business()->id(),
            'user_id' => $eve->id(),
            'role_id' => $foreign,
        ]);

        $rows = $connection->fetchOne('SELECT COUNT(*) FROM core_tenant_membership WHERE user_id = ?', [$eve->id()]);
        self::assertSame(1, is_numeric($rows) ? (int) $rows : -1, 'the row naming the other business\'s role is there');

        $this->signInAs('eve@example.com');

        self::assertFalse($this->permissions()->isAllowed(Resource::Content, Privilege::Edit));
        self::assertFalse($this->permissions()->isAllowed(Resource::Administration, Privilege::View));
    }

    /**
     * The second business's own role under the first one's name, allowing
     * something else, held there by an account of its own.
     */
    private function theSecondBusinessComposesAnEditorOfItsOwn(): void
    {
        $accounts = $this->container()->getByType(Accounts::class);
        $dora = $this->account($accounts, 'dora@example.com', 'Dora Dinichthys');

        $entityManager = $this->container()->getByType(EntityManagerInterface::class);
        $editor = Role::ofBusiness(
            $this->tenantWithoutAnyRole(),
            self::EDITOR,
            'Content editor',
            ['app.administration.content:delete'],
        );
        $entityManager->persist($editor);
        $entityManager->persist(new Membership($this->tenantWithoutAnyRole(), $dora, $editor));
        $entityManager->flush();
    }

    /**
     * A tenant, a second tenant, an account holding a role in the first one
     * only, and the process working inside the first.
     *
     * The second tenant has somebody else administering it, so that being told
     * no there is this account holding nothing rather than there being nothing
     * to hold: an empty access list would answer no to everything and look
     * exactly like isolation working.
     *
     * The account is also granted the role directly, the way core_user_role
     * lets it be. That is deliberate and it has to stay: a permission is held
     * in a tenant, so the direct grant must not be what answers - and the
     * second tenant is where that shows.
     *
     * @param list<string> $editing what the role this account holds is
     *     assembled from
     */
    private function installation(array $editing = ['app.administration:view', 'app.administration.content:edit']): void
    {
        $this->schema = Database::schemaFor(self::class);
        $this->container = Boot::coreAlone();
        Migrations::run($this->container);

        $this->bikes = Tenants::enter($this->container, 'Ammonite Bikes');
        $this->books = Tenants::create($this->container, 'Trilobite Books');

        $accounts = $this->container->getByType(Accounts::class);
        $alice = $this->account($accounts, 'alice@example.com', 'Alice Ammonite');
        $bob = $this->account($accounts, 'bob@example.com', 'Bob Belemnite');

        $entityManager = $this->container->getByType(EntityManagerInterface::class);
        $this->editor = $editor = Role::ofBusiness($this->bikes, self::EDITOR, 'Content editor', $editing);
        $administrator = Role::ofBusiness(
            $this->books,
            self::ADMINISTRATOR,
            'Administrator',
            ['app.administration:view', 'app.administration.content:edit'],
        );
        $entityManager->persist($editor);
        $entityManager->persist($administrator);
        $entityManager->persist(new Membership($this->bikes, $alice, $editor));
        $entityManager->persist(new Membership($this->books, $bob, $administrator));
        $entityManager->flush();

        $alice->grant($editor);
        $accounts->save($alice);
    }

    /**
     * An account with a password generated here and kept only for the length
     * of the run. Nothing that could be signed in with is written down: the
     * repository is public, and a fixture password would be a disclosure git
     * keeps for ever.
     */
    private function account(Accounts $accounts, string $email, string $name, bool $landlord = false): User
    {
        $password = Random::generate(24, 'a-zA-Z0-9');
        $this->passwords[$email] = $password;

        $account = new User(
            $email,
            $this->container()->getByType(Passwords::class)->hash($password),
            $name,
            new DateTimeImmutable('2026-09-06T08:00:00+00:00'),
            landlord: $landlord,
        );
        $accounts->save($account);

        return $account;
    }

    private function signIn(): void
    {
        $this->signInAs('alice@example.com');
    }

    private function signInAs(string $email): void
    {
        $this->container()->getByType(SignedIn::class)->login($email, $this->passwords[$email] ?? '');
    }

    /** The tenant the process starts in, where the first account holds its role. */
    private function business(): Tenant
    {
        self::assertInstanceOf(Tenant::class, $this->bikes);

        return $this->bikes;
    }

    private function tenantWithoutAnyRole(): Tenant
    {
        self::assertInstanceOf(Tenant::class, $this->books);

        return $this->books;
    }

    private function permissions(): Permissions
    {
        return $this->container()->getByType(Permissions::class);
    }

    private function container(): Container
    {
        self::assertInstanceOf(Container::class, $this->container);

        return $this->container;
    }
}
