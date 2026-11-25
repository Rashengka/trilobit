<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Addition;
use Trilobit\Core\Security\Grant;
use Trilobit\Core\Security\PasswordLinks;
use Trilobit\Core\Security\People;
use Trilobit\Core\Security\PeopleRefused;
use Trilobit\Core\Security\PermissionStructure;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;
use Trilobit\Core\Tenancy\Tenancy;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * Who belongs to a business, changed through the one service that guards it.
 *
 * Every guard is asked of the service rather than of a page, because a page is
 * one way in and the service is every way: a form, a signal, a command, a
 * screen nobody has written yet. So each rule is shown holding for somebody
 * who may manage people and is refused anyway - the role in between, which may
 * add and remove people and holds nothing else - as well as for somebody who
 * may not manage people at all.
 *
 * The last owner is the one guard no single request can reach: to take an
 * owner's role away somebody has to be able to give it, which only an owner
 * can, and nobody changes their own. It is reached by two owners removing each
 * other at the same moment, and that is made to happen rather than hoped for.
 */
#[CoversNothing]
final class PeopleTest extends TestCase
{
    /** How long the other owner's process gets to reach the locking read; see SetupWizardTest. */
    private const int SECONDS_TO_REACH_THE_LOCK = 30;

    /** The role in between: may manage people, and holds nothing else. */
    private const string MANAGER = 'moss@example.com';

    private string $schema = '';

    private ?Container $container = null;

    /** @var array<string, string> by the address the account signs in with */
    private array $passwords = [];

    private ?Tenant $ammonite = null;

    private ?Tenant $belemnite = null;

    /** @var array<string, Role> by code, the roles that may be held in the first business */
    private array $roles = [];

    protected function tearDown(): void
    {
        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->container = null;
        $this->ammonite = null;
        $this->belemnite = null;
        $this->roles = [];
        $this->passwords = [];

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testSomebodyWhoMayManagePeopleMayAddSomebodyHoldingNoMoreThanThey(): void
    {
        $this->signIn(self::MANAGER);

        $added = $this->people()->add('new@example.com', 'Nell Newt', $this->roleId('onlooker'));

        self::assertSame('new@example.com', $added->account->email());
        self::assertSame(['onlooker'], $this->rolesOf('new@example.com'));
    }

    /**
     * Nobody gives more than they have (H5). The role in between may add
     * people, and still not as an editor, which holds what they do not, and
     * never as an owner.
     */
    public function testNobodyGivesARoleHoldingMoreThanTheyDo(): void
    {
        $this->signIn(self::MANAGER);

        foreach (['editor', Role::OWNER] as $code) {
            try {
                $this->people()->add('new-' . $code . '@example.com', 'Nell Newt', $this->roleId($code));
                self::fail('the role in between gave ' . $code);
            } catch (PeopleRefused) {
                self::assertNull($this->accounts()->withEmail('new-' . $code . '@example.com'), 'a refused addition made an account');
            }
        }

        $this->expectException(PeopleRefused::class);
        $this->people()->give($this->personId('nora@example.com'), $this->roleId('editor'));
    }

    public function testTheOwnerMayGiveAnyRoleThisBusinessHas(): void
    {
        $this->signIn('alice@example.com');

        $this->people()->add('new@example.com', 'Nell Newt', $this->roleId('editor'));
        $this->people()->give($this->personId('new@example.com'), $this->roleId(Role::OWNER));

        self::assertSame(['editor', Role::OWNER], $this->rolesOf('new@example.com'));
    }

    /** What the form offers is what may be given, asked the same way. */
    public function testWhatIsOfferedIsWhatThisPersonMayGive(): void
    {
        $this->signIn(self::MANAGER);
        self::assertSame(['onlooker', 'people'], $this->codes($this->people()->rolesOffered()));

        $this->signIn('alice@example.com');
        self::assertSame(['editor', 'onlooker', Role::OWNER, 'people'], $this->codes($this->people()->rolesOffered()));
    }

    public function testNobodyChangesTheirOwnMembership(): void
    {
        $this->signIn('alice@example.com');

        $this->assertRefused(fn() => $this->people()->remove($this->membershipId('alice@example.com', Role::OWNER)));
        $this->assertRefused(fn() => $this->people()->give($this->personId('alice@example.com'), $this->roleId('editor')));
        $this->assertRefused(fn(): Addition => $this->people()->add('alice@example.com', 'Alice Ammonite', $this->roleId('editor')));

        $this->signIn(self::MANAGER);
        $this->assertRefused(fn() => $this->people()->remove($this->membershipId(self::MANAGER, 'people')));

        self::assertSame([Role::OWNER], $this->rolesOf('alice@example.com'));
        self::assertSame(['people'], $this->rolesOf(self::MANAGER));
    }

    public function testRemovingTakesTheMembershipAwayAndLeavesTheAccount(): void
    {
        $this->signIn(self::MANAGER);

        $this->people()->remove($this->membershipId('nora@example.com', 'onlooker'));

        self::assertSame([], $this->rolesOf('nora@example.com'));
        $kept = $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM core_tenant_membership m JOIN core_user u ON u.id = m.user_id WHERE u.email = ?',
            ['nora@example.com'],
        );
        self::assertIsNumeric($kept);
        self::assertSame(0, (int) $kept, 'the membership was kept rather than deleted');
        self::assertInstanceOf(User::class, $this->accounts()->withEmail('nora@example.com'));
    }

    /**
     * Taking a role away is the other half of giving it, and held to the same
     * line: somebody who could not have given a role cannot take it from
     * whoever holds it - or the role in between could empty a business of its
     * editors and its owners.
     */
    public function testNobodyTakesAwayARoleTheyCouldNotHaveGiven(): void
    {
        $this->signIn(self::MANAGER);

        $this->assertRefused(fn() => $this->people()->remove($this->membershipId('eddie@example.com', 'editor')));
        $this->assertRefused(fn() => $this->people()->remove($this->membershipId('alice@example.com', Role::OWNER)));

        self::assertSame(['editor'], $this->rolesOf('eddie@example.com'));
        self::assertSame([Role::OWNER], $this->rolesOf('alice@example.com'));
    }

    /**
     * The rights are the ones the question is about - `app.administration.account`
     * - asked the way every page asks. Holding something else, or nothing,
     * does not reach them.
     */
    public function testWithoutTheRightToManagePeopleNothingIsChanged(): void
    {
        foreach (['eddie@example.com', 'nora@example.com'] as $email) {
            $this->signIn($email);

            $this->assertRefused(fn(): Addition => $this->people()->add('new@example.com', 'Nell Newt', $this->roleId('onlooker')));
            $this->assertRefused(fn() => $this->people()->give($this->personId(self::MANAGER), $this->roleId('onlooker')));
            $this->assertRefused(fn() => $this->people()->remove($this->membershipId(self::MANAGER, 'people')));
            $this->assertRefused(fn(): string => $this->people()->invitationFor($this->personId('ivo@example.com')));
        }

        self::assertNull($this->accounts()->withEmail('new@example.com'));
        self::assertSame(['people'], $this->rolesOf(self::MANAGER));
    }

    /** Each act asks for its own privilege: adding is not changing, and neither is removing. */
    public function testEachActAsksForItsOwnPrivilege(): void
    {
        $this->memberWith('vera@example.com', 'Vera Volute', 'viewer', [
            new Grant(Resource::Account, Privilege::View),
            new Grant(Resource::Account, Privilege::Add),
        ]);
        $this->signIn('vera@example.com');

        $this->people()->add('new@example.com', 'Nell Newt', $this->roleId('onlooker'));
        $this->assertRefused(fn() => $this->people()->give($this->personId('new@example.com'), $this->roleId('viewer')));
        $this->assertRefused(fn() => $this->people()->remove($this->membershipId('new@example.com', 'onlooker')));
    }

    public function testSomebodyOfAnotherBusinessCannotBeReachedFromThisOne(): void
    {
        $this->signIn('alice@example.com');

        $this->assertRefused(fn() => $this->people()->remove($this->membershipId('bruno@example.com', 'proofreader')));
        $this->assertRefused(fn() => $this->people()->give($this->personId('bruno@example.com'), $this->roleId('editor')));
        $this->assertRefused(fn(): string => $this->people()->invitationFor($this->personId('bruno@example.com')));
        $this->assertRefused(fn(): Addition => $this->people()->add('new@example.com', 'Nell Newt', $this->roleOfBelemnite()));

        $this->switchTo($this->belemnite());
        self::assertSame(['proofreader'], $this->rolesOf('bruno@example.com'));
    }

    /**
     * Somebody new has no password, and is sent the link that sets one: the
     * addition hands back its token, which is the only time it exists outside
     * the message.
     */
    public function testAddingSomebodyNewInvitesThem(): void
    {
        $this->signIn('alice@example.com');

        $added = $this->people()->add('new@example.com', 'Nell Newt', $this->roleId('editor'));

        self::assertFalse($added->hadAnAccount);
        self::assertFalse($added->account->hasPassword());
        self::assertIsString($added->invitation);
        self::assertSame('new@example.com', $this->links()->holderOf($added->invitation)?->email());
    }

    /**
     * An operator who has an account already - in another business - is given
     * the role and nothing else: no link, and their name as it was. That this
     * tells whoever added them the account exists is accepted (O2).
     */
    public function testAddingSomebodyWhoHasAnAccountGivesThemTheRoleAndSaysSo(): void
    {
        $this->signIn('alice@example.com');

        $added = $this->people()->add('bruno@example.com', 'Somebody Else', $this->roleId('editor'));

        self::assertTrue($added->hadAnAccount);
        self::assertNull($added->invitation);
        self::assertSame('Bruno Belemnite', $added->account->name());
        self::assertSame(['editor'], $this->rolesOf('bruno@example.com'));
    }

    public function testSomebodyAlreadyHoldingTheRoleIsNotGivenItTwice(): void
    {
        $this->signIn('alice@example.com');

        $this->assertRefused(fn(): Addition => $this->people()->add('eddie@example.com', 'Eddie Echinoid', $this->roleId('editor')));
        $this->assertRefused(fn() => $this->people()->give($this->personId('eddie@example.com'), $this->roleId('editor')));
    }

    /**
     * The installation's administrator holds a role in a business only when
     * that is said outright (Membership::forTheInstallationsAdministrator()),
     * which a form in one business's administration is not the place for.
     */
    public function testTheInstallationsAdministratorIsNotAddedTheOrdinaryWay(): void
    {
        $this->signIn('alice@example.com');

        $this->assertRefused(fn(): Addition => $this->people()->add('landlord@example.com', 'Lars Landlord', $this->roleId('editor')));
    }

    public function testAnInvitationIsSentAgainAsANewLinkOnlyToSomebodyWithoutAPassword(): void
    {
        $this->signIn('alice@example.com');
        $first = $this->people()->add('new@example.com', 'Nell Newt', $this->roleId('editor'))->invitation;
        self::assertIsString($first);

        $again = $this->people()->invitationFor($this->personId('new@example.com'));

        self::assertNull($this->links()->holderOf($first), 'the first link still opens next to the new one');
        self::assertSame('new@example.com', $this->links()->holderOf($again)?->email());

        $this->assertRefused(fn(): string => $this->people()->invitationFor($this->personId('nora@example.com')));
    }

    /**
     * Two owners removing each other at the same moment: one of them stays.
     *
     * Neither request can see the other coming. Each is an owner when it asks,
     * each sees two owners, and each would take the other one's role - leaving
     * a business nobody owns, which nobody can give an owner again. What stops
     * the second is that it reads the owners with a locking read inside its
     * transaction: it waits for the first to finish and then sees one owner,
     * the one it was about to remove.
     *
     * This test is the first request: it has read the owners with the same
     * locking read and removed the other owner's role, and holds its
     * transaction open. The second is a process of its own, signed in as the
     * owner being removed. It is let go only once the server shows it waiting
     * on the owners, and then the first commits. A plain read would not have
     * waited, and the test says so rather than passing by luck.
     */
    public function testOfTwoOwnersRemovingEachOtherAtOnceOneRemainsTheOwner(): void
    {
        $this->memberWithRole('otto@example.com', 'Otto Orthoceras', $this->ownersRole());
        $alices = $this->membershipId('alice@example.com', Role::OWNER);
        $ottos = $this->membershipId('otto@example.com', Role::OWNER);

        $connection = $this->connection();
        $connection->beginTransaction();
        $connection->fetchFirstColumn(
            'SELECT m.id FROM core_tenant_membership m JOIN core_role r ON r.id = m.role_id'
            . ' WHERE m.tenant_id = ? AND r.code = ? AND r.tenant_id IS NULL FOR UPDATE',
            [$this->ammonite()->id(), Role::OWNER],
        );
        $connection->executeStatement('DELETE FROM core_tenant_membership WHERE id = ?', [$ottos]);

        [$met, $status, $output] = $this->removedElsewhereOnceItWaits($connection, 'otto@example.com', $alices);

        self::assertTrue($met, 'the other owner never waited on the owners, so it read past this removal: ' . $output);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('outcome:refused', $output);

        $this->entityManager()->clear();
        self::assertSame([Role::OWNER], $this->rolesOf('alice@example.com'));
        self::assertSame([], $this->rolesOf('otto@example.com'));
    }

    /** @param callable(): mixed $act */
    private function assertRefused(callable $act): void
    {
        try {
            $act();
        } catch (PeopleRefused $refused) {
            self::assertNotSame('', $refused->getMessage(), 'a refusal has to say why');

            return;
        }

        self::fail('it was done');
    }

    /** @return list<string> the codes of the roles $email holds in the business the process is in, sorted */
    private function rolesOf(string $email): array
    {
        $codes = $this->connection()->fetchFirstColumn(
            'SELECT r.code FROM core_tenant_membership m JOIN core_role r ON r.id = m.role_id'
            . ' JOIN core_user u ON u.id = m.user_id WHERE u.email = ? AND m.tenant_id = ?',
            [$email, $this->container()->getByType(Tenancy::class)->current()],
        );
        $codes = array_map(static fn(mixed $code): string => is_string($code) ? $code : '', $codes);
        sort($codes);

        return $codes;
    }

    /**
     * @param list<Role> $roles
     *
     * @return list<string>
     */
    private function codes(array $roles): array
    {
        $codes = array_map(static fn(Role $role): string => $role->code(), $roles);
        sort($codes);

        return $codes;
    }

    private function roleId(string $code): int
    {
        $this->container();

        return ($this->roles[$code] ?? self::fail('no role ' . $code))->id() ?? self::fail('the role ' . $code . ' was never saved');
    }

    private function ownersRole(): Role
    {
        $this->container();

        return $this->roles[Role::OWNER] ?? self::fail('no owner\'s role');
    }

    private function roleOfBelemnite(): int
    {
        $id = $this->connection()->fetchOne('SELECT id FROM core_role WHERE code = ?', ['proofreader']);

        return is_numeric($id) ? (int) $id : self::fail('the other business has no role of its own');
    }

    private function personId(string $email): int
    {
        return $this->accounts()->withEmail($email)?->id() ?? self::fail('there is no account ' . $email);
    }

    private function membershipId(string $email, string $code): int
    {
        $id = $this->connection()->fetchOne(
            'SELECT m.id FROM core_tenant_membership m JOIN core_role r ON r.id = m.role_id'
            . ' JOIN core_user u ON u.id = m.user_id WHERE u.email = ? AND r.code = ?',
            [$email, $code],
        );

        return is_numeric($id) ? (int) $id : self::fail($email . ' holds no ' . $code);
    }

    private function signIn(string $email): void
    {
        $this->container();
        $signedIn = $this->container()->getByType(SignedIn::class);
        $signedIn->logout(true);
        $signedIn->login($email, $this->passwords[$email] ?? self::fail('no account ' . $email));
    }

    private function people(): People
    {
        return $this->container()->getByType(People::class);
    }

    private function links(): PasswordLinks
    {
        return $this->container()->getByType(PasswordLinks::class);
    }

    private function accounts(): Accounts
    {
        return $this->container()->getByType(Accounts::class);
    }

    private function connection(): Connection
    {
        return $this->container()->getByType(Connection::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->container()->getByType(EntityManagerInterface::class);
    }

    private function ammonite(): Tenant
    {
        $this->container();

        return $this->ammonite ?? self::fail('no first business');
    }

    private function belemnite(): Tenant
    {
        $this->container();

        return $this->belemnite ?? self::fail('no second business');
    }

    private function switchTo(Tenant $business): void
    {
        Tenants::switchTo($this->container(), $business);
    }

    /**
     * Two businesses. In the first an owner, the role in between, an editor,
     * somebody holding nothing, and somebody still waiting for their link; in
     * the second somebody holding its own role; and the installation's
     * administrator, in neither.
     */
    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::coreAlone();
        $this->container = $container;
        Migrations::run($container);

        $this->belemnite = Tenants::enter($container, 'Belemnite Books');
        $this->memberWithRole('bruno@example.com', 'Bruno Belemnite', $this->businessRole($this->belemnite, 'proofreader', [
            new Grant(Resource::Content, Privilege::View),
        ]));

        // Moving into a second business empties the entity manager
        // (Trilobit\Core\Tenancy\Tenancy::enter()), so the business is read
        // back rather than held on to as it was made.
        $made = Tenants::enter($container, 'Ammonite Bikes');
        $ammonite = $this->entityManager()->find(Tenant::class, $made->id()) ?? self::fail('the first business cannot be read back');
        $this->ammonite = $ammonite;
        $this->roles[Role::OWNER] = $this->accounts()->ownersRole($container->getByType(PermissionStructure::class));
        $this->entityManager()->flush();

        $this->memberWithRole('alice@example.com', 'Alice Ammonite', $this->roles[Role::OWNER]);
        $this->memberWith(self::MANAGER, 'Moss Mosasaur', 'people', [
            new Grant(Resource::Account, Privilege::View),
            new Grant(Resource::Account, Privilege::Add),
            new Grant(Resource::Account, Privilege::Edit),
            new Grant(Resource::Account, Privilege::Delete),
        ]);
        $this->memberWith('eddie@example.com', 'Eddie Echinoid', 'editor', [
            new Grant(Resource::Content, Privilege::View),
            new Grant(Resource::Content, Privilege::Edit),
        ]);
        $this->memberWith('nora@example.com', 'Nora Nautilus', 'onlooker', []);

        $ivo = User::invited('ivo@example.com', 'Ivo Isopod', new DateTimeImmutable());
        $this->accounts()->save($ivo);
        $this->entityManager()->persist(new Membership($ammonite, $ivo, $this->roles['onlooker']));
        $this->entityManager()->flush();
        $this->links()->issue($ivo);

        $this->accounts()->save(new User(
            'landlord@example.com',
            $container->getByType(Passwords::class)->hash(Random::generate(20)),
            'Lars Landlord',
            new DateTimeImmutable(),
            landlord: true,
        ));

        return $container;
    }

    /** @param list<Grant> $grants */
    private function memberWith(string $email, string $name, string $code, array $grants): void
    {
        $role = $this->businessRole($this->ammonite(), $code, $grants);
        $this->roles[$code] = $role;
        $this->memberWithRole($email, $name, $role);
    }

    /** @param list<Grant> $grants */
    private function businessRole(Tenant $business, string $code, array $grants): Role
    {
        $role = Role::ofBusiness($business, $code, ucfirst($code), array_map(
            static fn(Grant $grant): string => $grant->code(),
            $grants,
        ));
        $this->entityManager()->persist($role);
        $this->entityManager()->flush();

        return $role;
    }

    private function memberWithRole(string $email, string $name, Role $role): void
    {
        $container = $this->container ?? self::fail('no build yet');
        $password = Random::generate(20);
        $this->passwords[$email] = $password;

        $account = new User($email, $container->getByType(Passwords::class)->hash($password), $name, new DateTimeImmutable());
        $this->accounts()->save($account);

        $business = $role->business() ?? $this->ammonite ?? self::fail('no business to hold the owner in');
        $this->entityManager()->persist(new Membership($business, $account, $role));
        $this->entityManager()->flush();
    }

    /**
     * People::remove() run the way a second owner's request runs it - in a
     * process of its own, signed in, with a connection of its own - while
     * $connection holds the owners locked; committed the moment that process
     * is seen waiting on the same locking read. See
     * SetupWizardTest::finishedElsewhereOnceItWaits(), which this follows.
     *
     * @return array{bool, int, string} whether it was seen waiting, its exit code, and what it printed
     */
    private function removedElsewhereOnceItWaits(Connection $connection, string $email, int $membership): array
    {
        $code = sprintf(
            'require %s; $c = %s::boot(); $c->getByType(%s::class)->enter(%d); $c->getByType(%s::class)->login(%s, %s);'
                . ' try { $c->getByType(%s::class)->remove(%d); echo "outcome:removed\n"; }'
                . ' catch (%s $refused) { echo "outcome:refused\n"; }',
            var_export(Bootstrap::rootDirectory() . '/vendor/autoload.php', true),
            Bootstrap::class,
            Tenancy::class,
            $this->ammonite()->id() ?? 0,
            SignedIn::class,
            var_export($email, true),
            var_export($this->passwords[$email] ?? '', true),
            People::class,
            $membership,
            PeopleRefused::class,
        );

        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            Bootstrap::rootDirectory(),
        );

        try {
            self::assertIsResource($process);

            $met = false;
            $deadline = microtime(true) + self::SECONDS_TO_REACH_THE_LOCK;
            while (!$met && microtime(true) < $deadline && proc_get_status($process)['running']) {
                $waiting = $connection->fetchOne(
                    'SELECT COUNT(*) FROM information_schema.PROCESSLIST'
                    . ' WHERE ID <> CONNECTION_ID() AND DB = DATABASE() AND INFO LIKE ?',
                    ['%FROM core_tenant_membership%FOR UPDATE%'],
                );
                $met = is_numeric($waiting) && (int) $waiting > 0;
                if (!$met) {
                    usleep(20_000);
                }
            }
        } finally {
            $connection->commit();
        }

        $output = '';
        foreach ($pipes as $pipe) {
            $output .= (string) stream_get_contents($pipe);
            fclose($pipe);
        }

        $status = proc_get_status($process);
        while ($status['running']) {
            usleep(10_000);
            $status = proc_get_status($process);
        }

        proc_close($process);

        return [$met, $status['exitcode'], $output];
    }
}
