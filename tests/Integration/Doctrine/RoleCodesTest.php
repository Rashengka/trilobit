<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Security\Accounts;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * Where a role's code is unique: among the roles of one business, and among
 * the roles of the application - which belong to no business at all.
 *
 * The second half is the one that is easy to lose. The obvious index, unique
 * over the business and the code, lets the application's roles in twice: their
 * business is NULL, and MariaDB does not hold two NULLs to be equal, so the
 * duplicate is accepted without a word. What is unique instead is the code
 * together with a column the database works out from the business, which is
 * 0 where there is none - see Trilobit\Core\Domain\User\Role. The rows are
 * written straight to the connection here, past the entity, because the claim
 * is about the table and it has to hold for a writer that is not this one.
 */
#[CoversNothing]
final class RoleCodesTest extends TestCase
{
    private string $schema = '';

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testTwoBusinessesMayEachHaveARoleUnderTheSameCode(): void
    {
        $container = $this->emptyDatabase();
        $entityManager = $container->getByType(EntityManagerInterface::class);
        $bikes = Tenants::enter($container, 'Ammonite Bikes');
        $books = Tenants::create($container, 'Trilobite Books');

        $entityManager->persist(Role::ofBusiness($bikes, 'editor', 'Bike editor'));
        $entityManager->persist(Role::ofBusiness($books, 'editor', 'Book editor'));
        $entityManager->flush();

        self::assertSame(2, $this->rowsUnder($container, 'editor'));
    }

    public function testOneBusinessCannotHaveTwoRolesUnderTheSameCode(): void
    {
        $container = $this->emptyDatabase();
        $entityManager = $container->getByType(EntityManagerInterface::class);
        $bikes = Tenants::enter($container, 'Ammonite Bikes');

        $entityManager->persist(Role::ofBusiness($bikes, 'editor', 'Editor'));
        $entityManager->persist(Role::ofBusiness($bikes, 'editor', 'Editor again'));

        $this->expectException(UniqueConstraintViolationException::class);

        $entityManager->flush();
    }

    /**
     * The half an index over the business and the code would lose, and the one
     * Trilobit\Core\Security\Accounts::applicationRoleMadeIfMissing() stands
     * on: two runs making the owner's role at the same moment are told apart
     * by this refusal and nothing else.
     */
    public function testTheApplicationCannotHaveTwoRolesUnderTheSameCode(): void
    {
        $container = $this->emptyDatabase();
        $connection = $container->getByType(EntityManagerInterface::class)->getConnection();

        $connection->insert('core_role', ['code' => Role::OWNER, 'name' => 'Owner', 'permissions' => '[]']);

        try {
            $connection->insert('core_role', ['code' => Role::OWNER, 'name' => 'Owner again', 'permissions' => '[]']);
            self::fail('a second role of the application under the same code was accepted');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(1, $this->rowsUnder($container, Role::OWNER));
        }
    }

    /**
     * Asking for a role by its code asks for the application's. A business's
     * role under that code is somebody's own and never the answer - handed to
     * the command that makes an owner, it would make one business's role
     * everybody's.
     */
    public function testARoleLookedUpByCodeIsTheApplicationsAndNeverABusinesss(): void
    {
        $container = $this->emptyDatabase();
        $entityManager = $container->getByType(EntityManagerInterface::class);
        $accounts = $container->getByType(Accounts::class);
        $bikes = Tenants::enter($container, 'Ammonite Bikes');

        $entityManager->persist(Role::ofBusiness($bikes, 'editor', 'Bike editor'));
        $entityManager->flush();

        self::assertNull($accounts->applicationRole('editor'));

        $entityManager->persist(new Role('editor', 'Editor of the application'));
        $entityManager->flush();

        $found = $accounts->applicationRole('editor');
        self::assertInstanceOf(Role::class, $found);
        self::assertSame('Editor of the application', $found->name());
        self::assertNull($found->business());
    }

    /** The role the owner's command makes is the application's even when it is made from inside a business. */
    public function testARoleMadeIfMissingIsTheApplicationsWhereverItIsAskedFrom(): void
    {
        $container = $this->emptyDatabase();
        $bikes = Tenants::enter($container, 'Ammonite Bikes');
        $container->getByType(EntityManagerInterface::class)->persist(Role::ofBusiness($bikes, 'editor', 'Bike editor'));
        $container->getByType(EntityManagerInterface::class)->flush();

        $role = $container->getByType(Accounts::class)->applicationRoleMadeIfMissing('editor', 'Editor');

        self::assertSame('Editor', $role->name());
        self::assertNull($role->business());
        self::assertSame(2, $this->rowsUnder($container, 'editor'));
    }

    private function rowsUnder(Container $container, string $code): int
    {
        $count = $container->getByType(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM core_role WHERE code = ?', [$code]);

        return is_numeric($count) ? (int) $count : -1;
    }

    private function emptyDatabase(): Container
    {
        $this->schema = Database::schemaFor(self::class);
        $container = Boot::coreAlone();
        Migrations::run($container);

        return $container;
    }
}
