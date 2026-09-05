<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\Tenancy\Domain;
use Trilobit\Core\Domain\Tenancy\LanguageStrategy;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * The tenant, the hosts it answers at, and the way a person belongs to it,
 * against the database the migrations build.
 *
 * Three claims here are the shape of the decision rather than the behaviour of
 * the objects, and each of them is a claim about what the database refuses. A
 * host naming two tenants would be a request with two answers; an account
 * whose address is unique only within a tenant would be a person who is four
 * people; a tenant able to hold two language strategies at once would need
 * something to decide between them on every request.
 */
#[CoversNothing]
final class TenancyEntitiesTest extends TestCase
{
    private string $schema = '';

    private ?Container $container = null;

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            Database::drop($this->schema);
        }
    }

    public function testATenantAnswersAtEveryDomainItWasGiven(): void
    {
        $entityManager = $this->emptyDatabase();

        Tenants::enter($this->container(), 'Ammonite Bikes', 'example.com', 'example.org');
        $entityManager->clear();

        $hosts = [];
        foreach ($entityManager->getRepository(Domain::class)->findBy([], ['host' => 'ASC']) as $domain) {
            $hosts[] = $domain->host();
            self::assertSame('Ammonite Bikes', $domain->tenant()->name());
        }

        self::assertSame(['example.com', 'example.org'], $hosts);
    }

    /**
     * Two tenants claiming one host is not a collision anybody resolves - it
     * is the question "whose request is this" having two answers. The database
     * is where that is refused, because a rule in the application would be a
     * rule a second writer could go round.
     */
    public function testAHostCanNameOnlyOneTenant(): void
    {
        $entityManager = $this->emptyDatabase();

        Tenants::enter($this->container(), 'Ammonite Bikes', 'example.com');
        $second = Tenants::create($this->container(), 'Brachiopod Books');

        $entityManager->persist(new Domain('example.com', $second));

        $this->expectException(UniqueConstraintViolationException::class);

        $entityManager->flush();
    }

    /**
     * The account is global and belonging to a tenant is a relationship, so
     * one address signs one person in and what they may do is answered per
     * tenant.
     */
    public function testOneAccountBelongsToTwoTenantsWithADifferentRoleInEach(): void
    {
        $entityManager = $this->emptyDatabase();

        $bikes = Tenants::enter($this->container(), 'Ammonite Bikes');
        $books = Tenants::create($this->container(), 'Brachiopod Books');
        $account = new User(
            'alice@example.com',
            'not a real hash',
            'Alice Ammonite',
            new DateTimeImmutable('2026-09-05T08:00:00+00:00'),
        );
        $administrator = new Role('administrator', 'Administrator', ['administration']);
        $editor = new Role('editor', 'Editor', ['content.write']);

        foreach ([$account, $administrator, $editor] as $entity) {
            $entityManager->persist($entity);
        }

        $entityManager->persist(new Membership($bikes, $account, $administrator));
        $entityManager->persist(new Membership($books, $account, $editor));
        $entityManager->flush();
        $entityManager->clear();

        // Read from inside each tenant in turn, because that is the only way
        // memberships are ever read: the filter scopes this table like every
        // other tenanted one, so "all of them" always means "all of this
        // tenant's".
        self::assertSame(['administrator'], $this->rolesHeldIn($bikes));
        self::assertSame(['editor'], $this->rolesHeldIn($books));
    }

    /**
     * @return list<string>
     */
    private function rolesHeldIn(Tenant $tenant): array
    {
        Tenants::switchTo($this->container(), $tenant);

        $codes = [];
        foreach ($this->container()->getByType(EntityManagerInterface::class)->getRepository(Membership::class)->findAll() as $membership) {
            self::assertSame('alice@example.com', $membership->user()->email());
            self::assertSame($tenant->name(), $membership->tenant()->name());
            $codes[] = $membership->role()->code();
        }

        sort($codes);

        return $codes;
    }

    /** Granting the same role in the same tenant twice is refused by the index, not by whoever remembers. */
    public function testTheSameRoleInTheSameTenantCannotBeGrantedTwice(): void
    {
        $entityManager = $this->emptyDatabase();

        $tenant = Tenants::enter($this->container(), 'Ammonite Bikes');
        $account = new User(
            'alice@example.com',
            'not a real hash',
            'Alice Ammonite',
            new DateTimeImmutable('2026-09-05T08:00:00+00:00'),
        );
        $role = new Role('administrator', 'Administrator', ['administration']);

        foreach ([$account, $role] as $entity) {
            $entityManager->persist($entity);
        }

        $entityManager->persist(new Membership($tenant, $account, $role));
        $entityManager->flush();

        $entityManager->persist(new Membership($tenant, $account, $role));

        $this->expectException(UniqueConstraintViolationException::class);

        $entityManager->flush();
    }

    /**
     * The strategy survives as the enumeration it was written as, which is
     * what makes "exactly one of the three" a fact of the row rather than a
     * rule: there is one column, so a combination has nowhere to be written.
     */
    public function testTheLanguageStrategyIsOneChoiceAndComesBackAsOne(): void
    {
        $entityManager = $this->emptyDatabase();

        $tenant = new Tenant(
            'Ammonite Bikes',
            new DateTimeImmutable('2026-09-05T08:00:00+00:00'),
            LanguageStrategy::Domain,
        );
        $entityManager->persist($tenant);
        $entityManager->flush();
        $entityManager->clear();

        $read = $entityManager->getRepository(Tenant::class)->findOneBy(['name' => 'Ammonite Bikes']);

        self::assertInstanceOf(Tenant::class, $read);
        self::assertSame(LanguageStrategy::Domain, $read->languageStrategy());
    }

    private function emptyDatabase(): EntityManagerInterface
    {
        $this->schema = Database::schemaFor(self::class);
        $this->container = Boot::coreAlone();
        Migrations::run($this->container);

        return $this->container->getByType(EntityManagerInterface::class);
    }

    private function container(): Container
    {
        self::assertInstanceOf(Container::class, $this->container);

        return $this->container;
    }
}
