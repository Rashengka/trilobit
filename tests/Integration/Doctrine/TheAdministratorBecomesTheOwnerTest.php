<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Migrations\Version20260912093939;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Authenticator;
use Trilobit\Core\Security\Grant;
use Trilobit\Core\Security\Identity;
use Trilobit\Core\Security\PermissionStructure;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Tenants;

/**
 * The role `app:account --tenant` used to make, carried over to the owner of
 * the business - and back again.
 *
 * Up, the row is the owner's: its code, its name, and the whole of the
 * application instead of the pairs the earlier build listed. Down, it is the
 * administrator again, holding exactly the pairs the structure offered when
 * the migration was written - the string the earlier build's command wrote,
 * byte for byte, because that is what that build expects to find.
 *
 * **Somebody already signed in is the case worth a test of its own.** The
 * session holds who they are and none of what they may do, and the set is read
 * again at the start of every request; so an account signed in under the old
 * code has to come out of the migration holding the new one on its next click,
 * without signing in again and without an error.
 *
 * Every step is a build of its own, the way every run of
 * `bin/trilobit migrations:migrate` is a process of its own; see
 * Trilobit\Tests\Integration\Doctrine\RolePiecesMoveIntoTheTreeTest for why.
 */
#[CoversNothing]
final class TheAdministratorBecomesTheOwnerTest extends TestCase
{
    /** The last migration before the administrator became the owner. */
    private const string BEFORE = Version20260912093939::class;

    private const string LATEST = 'latest';

    /**
     * What the earlier build's `app:account --tenant` wrote into the role: every
     * pair its structure offered, in the order it read them.
     */
    private const string EVERY_PAIR_BEFORE = '["app:view","app.administration:view",'
        . '"app.administration.account:view","app.administration.account:add","app.administration.account:edit",'
        . '"app.administration.account:delete","app.administration.account:purge",'
        . '"app.administration.content:view","app.administration.content:add","app.administration.content:edit",'
        . '"app.administration.content:delete","app.administration.content:purge",'
        . '"app.administration.content:export","app.administration.content:change_priority",'
        . '"app.redirection:view","app.redirection:force_redirect"]';

    /**
     * Rows as the earlier build left them, by id order: the administrator, and
     * two that must not be touched - a role of a business's own, and one whose
     * code merely begins like the administrator's. Spaced the way no build
     * writes it, so that being rewritten anyway would show.
     *
     * @var list<array{string, string, string}> code, name, permissions
     */
    private const array BEFORE_ROWS = [
        ['administrator', 'Administrator', self::EVERY_PAIR_BEFORE],
        ['editor', 'Editor', '["app.administration.content:edit", "app.administration.content:view"]'],
        ['administrators', 'Administrators', '["app.administration:view"]'],
    ];

    /** @var list<array{string, string, string}> */
    private const array AFTER_ROWS = [
        ['owner', 'Owner', '["app:*"]'],
        ['editor', 'Editor', '["app.administration.content:edit", "app.administration.content:view"]'],
        ['administrators', 'Administrators', '["app.administration:view"]'],
    ];

    private string $schema = '';

    private ?Container $container = null;

    protected function tearDown(): void
    {
        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->container?->getByType(Connection::class)->close();
        $this->container = null;

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testTheAdministratorBecomesTheOwnerHoldingTheWholeApplication(): void
    {
        $this->rolesWrittenByAnEarlierBuild();

        $this->migrate(self::LATEST);

        self::assertSame(self::AFTER_ROWS, $this->rolesStored());
    }

    /** Up, down and up again: down gives back what the earlier build wrote, and up can then run again. */
    public function testTakingItBackGivesTheEarlierBuildItsAdministrator(): void
    {
        $this->rolesWrittenByAnEarlierBuild();

        $this->migrate(self::LATEST);
        $this->migrate(self::BEFORE);

        self::assertSame(self::BEFORE_ROWS, $this->rolesStored());

        $this->migrate(self::LATEST);

        self::assertSame(self::AFTER_ROWS, $this->rolesStored());
    }

    /**
     * The pairs down writes are the pairs this structure offered when the
     * migration was written. Once the structure grows, this is the test that
     * says so - and the answer is to leave the migration alone, because it
     * describes the build it was written for; the list here is then changed
     * to what that build offered, not to what the structure offers now.
     */
    public function testDownListsEveryPairTheStructureOfferedWhenItWasWritten(): void
    {
        $written = json_decode(self::EVERY_PAIR_BEFORE, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(
            array_map(
                static fn(Grant $pair): string => $pair->code(),
                PermissionStructure::of(Bootstrap::rootDirectory())->everyPair(),
            ),
            $written,
        );
    }

    /**
     * Signed in before the migration, under the old code; after it, the next
     * request holds the owner's role and may do everything - with nobody
     * signing in again, and no error on the way.
     */
    public function testSomebodySignedInAsTheAdministratorIsTheOwnerOnTheirNextRequest(): void
    {
        $this->schema = Database::schemaFor(self::class);
        $this->migrate(self::BEFORE);

        $this->container = Boot::coreAlone();
        $business = Tenants::enter($this->container, 'Ammonite Bikes');
        $password = Random::generate(24, 'a-zA-Z0-9');
        $account = new User(
            'alice@example.com',
            $this->container->getByType(Passwords::class)->hash($password),
            'Alice Ammonite',
            new DateTimeImmutable('2026-09-12T08:00:00+00:00'),
        );
        $this->container->getByType(Accounts::class)->save($account);

        $entityManager = $this->container->getByType(EntityManagerInterface::class);
        $administrator = new Role('administrator', 'Administrator', json_decode(
            self::EVERY_PAIR_BEFORE,
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
        $entityManager->persist($administrator);
        $entityManager->persist(new Membership($business, $account, $administrator));
        $entityManager->flush();

        $this->container->getByType(SignedIn::class)->login('alice@example.com', $password);
        self::assertSame(['administrator'], $this->container->getByType(SignedIn::class)->getRoles());

        $this->migrate(self::LATEST);
        $this->nextRequestIn($business);

        $signedIn = $this->container()->getByType(SignedIn::class);
        self::assertTrue($signedIn->isLoggedIn(), 'nobody signed in again');
        self::assertSame([Role::OWNER], $signedIn->getRoles());
        self::assertTrue($signedIn->isAllowed(Resource::Account, Privilege::Purge));
        self::assertTrue($signedIn->isAllowed(Resource::Redirection, Privilege::ForceRedirect));

        // An identity carrying the old code itself - one an earlier build put
        // into a session with its roles still on - is answered the same way:
        // the set on it is replaced rather than merged.
        $person = $account->id();
        self::assertIsInt($person);
        $woken = $this->container()->getByType(Authenticator::class)
            ->wakeupIdentity(new Identity($person, ['administrator'], []));

        self::assertInstanceOf(Identity::class, $woken);
        self::assertSame([Role::OWNER], $woken->getRoles());
    }

    private function rolesWrittenByAnEarlierBuild(): void
    {
        $this->schema = Database::schemaFor(self::class);
        $this->migrate(self::BEFORE);

        $connection = Boot::coreAlone()->getByType(Connection::class);
        foreach (self::BEFORE_ROWS as [$code, $name, $permissions]) {
            $connection->insert('core_role', ['code' => $code, 'name' => $name, 'permissions' => $permissions]);
        }

        $connection->close();
    }

    /** @return list<array{string, string, string}> code, name and the column as it is stored, by id */
    private function rolesStored(): array
    {
        $connection = Boot::coreAlone()->getByType(Connection::class);

        /** @var list<array{code: string, name: string, permissions: string}> $rows */
        $rows = $connection->fetchAllAssociative('SELECT code, name, permissions FROM core_role ORDER BY id');
        $connection->close();

        return array_map(
            static fn(array $row): array => [$row['code'], $row['name'], $row['permissions']],
            $rows,
        );
    }

    private function container(): Container
    {
        self::assertInstanceOf(Container::class, $this->container);

        return $this->container;
    }

    /**
     * The next request: the services are built again and the session is not,
     * with the business settled first, as Trilobit\Core\Tenancy\TenantFromHost
     * settles it from the host before anything else is read.
     */
    private function nextRequestIn(Tenant $tenant): void
    {
        $this->container?->getByType(Connection::class)->close();

        $this->container = Boot::coreAlone();
        Tenants::switchTo($this->container, $tenant);
    }

    private function migrate(string $version): void
    {
        $build = Boot::coreAlone();
        $command = new MigrateCommand($build->getByType(DependencyFactory::class));
        new Application()->addCommand($command);

        $tester = new CommandTester($command);
        $status = $tester->execute(
            ['version' => $version, '--allow-no-migration' => true],
            ['interactive' => false, 'capture_stderr_separately' => true],
        );

        self::assertSame(
            Command::SUCCESS,
            $status,
            'the migrations did not run: ' . $tester->getDisplay() . $tester->getErrorOutput(),
        );

        $build->getByType(Connection::class)->close();
    }
}
