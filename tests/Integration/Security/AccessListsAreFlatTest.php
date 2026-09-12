<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use Nette\Security\Authorizator as NetteAuthorizator;
use Nette\Security\Passwords;
use Nette\Security\Permission;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Authorizator;
use Trilobit\Core\Security\Permissions;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * What the two services that build an access list really hand to Nette: one
 * privilege on one resource allowed to one role, and nothing else.
 *
 * Nette\Security\Permission can answer from more than a plain allow - a parent
 * of a resource, a parent of a role, a rule for every role, resource or
 * privilege at once, a deny - and every one of them makes an answer depend on
 * something the row that granted it does not say: which rule was written last,
 * which parent was added last, and whether inheritance brings back a right
 * that was taken away further down. So what the structure's tree means - a
 * right on a section opens what the section falls under - is worked out while
 * the list is composed, and Nette is given the result. That keeps the one
 * thing a deny needs: a right that is not in the list is not there by any
 * route.
 *
 * **It is asserted of what the services hold, not of the composition.** The
 * claim is about what reaches Nette, and a service that put its list together
 * some other way would pass every test written against the composition. Neither
 * service hands its list out and neither should - a list somebody can be given
 * is a second way of asking beside Nette's - so it is read with reflection,
 * after each has answered one real question and so has had to build it.
 *
 * **It cannot pass for want of a list.** An empty access list is as flat as
 * one can be, so each case asks first that the service found one to look at,
 * and that the door a section opens onto the administration - a right nobody
 * wrote, worked out from the tree - is in it as a rule of its own.
 */
#[CoversNothing]
final class AccessListsAreFlatTest extends TestCase
{
    private const string EDITOR = 'content-editor';

    private const string KEEPER = 'redirection-keeper';

    private string $schema = '';

    private ?Container $container = null;

    private string $password = '';

    protected function tearDown(): void
    {
        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->container?->getByType(Connection::class)->close();
        $this->container = null;
        $this->password = '';

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testTheListPermissionsAnswersFromHoldsOnlyPlainAllows(): void
    {
        $this->installation();
        $this->container()->getByType(SignedIn::class)->login('alice@example.com', $this->password);

        $permissions = $this->container()->getByType(Permissions::class);
        self::assertTrue($permissions->isAllowed(Resource::Administration, Privilege::View));

        $this->assertEveryListIsFlat($this->accessListsHeldBy($permissions));
    }

    public function testTheListTheFrameworkIsAnsweredFromHoldsOnlyPlainAllows(): void
    {
        $this->installation();

        $authorizator = $this->container()->getByType(NetteAuthorizator::class);
        self::assertInstanceOf(Authorizator::class, $authorizator);
        self::assertTrue($authorizator->isAllowed(self::EDITOR, Resource::Administration, Privilege::View));

        $this->assertEveryListIsFlat($this->accessListsHeldBy($authorizator));
    }

    /** @param list<Permission> $lists */
    private function assertEveryListIsFlat(array $lists): void
    {
        self::assertNotSame([], $lists, 'the service answered, so it has a list to be looked at');

        foreach ($lists as $access) {
            self::assertSame([], $this->anythingButPlainAllowsIn($access));
            self::assertEqualsCanonicalizing(
                array_map(static fn(Resource $resource): string => $resource->value, Resource::cases()),
                $access->getResources(),
                'every resource is registered, so that no question is an exception',
            );
            self::assertTrue(
                $this->isAllowedByARuleOfItsOwn($access, self::EDITOR, Resource::Administration, Privilege::View),
                'the door a section opens onto the administration is a rule on the administration itself',
            );
        }
    }

    /**
     * Everything in the list that is not `allow(role, resource, privilege)`,
     * said the way it would have been written.
     *
     * @return list<string>
     */
    private function anythingButPlainAllowsIn(Permission $access): array
    {
        $found = [];

        foreach ($access->getRoles() as $role) {
            foreach ($access->getRoleParents($role) as $parent) {
                $found[] = sprintf("role '%s' inherits from '%s'", $role, $parent);
            }
        }

        foreach ($access->getResources() as $resource) {
            foreach ($access->getResources() as $other) {
                if ($other !== $resource && $access->resourceInheritsFrom($resource, $other, true)) {
                    $found[] = sprintf("resource '%s' falls under '%s'", $resource, $other);
                }
            }
        }

        $rules = $this->rulesOf($access);
        if (($rules['allResources'] ?? null) !== ($this->rulesOf(new Permission())['allResources'] ?? null)) {
            $found[] = 'a rule about every resource at once';
        }

        $byResource = $rules['byResource'] ?? [];
        foreach (is_array($byResource) ? $byResource : [] as $resource => $onResource) {
            $onResource = is_array($onResource) ? $onResource : [];
            if (array_keys($onResource) !== ['byRole']) {
                $found[] = sprintf("a rule about every role at once on '%s'", $resource);
            }

            $byRole = $onResource['byRole'] ?? [];
            foreach (is_array($byRole) ? $byRole : [] as $role => $onRole) {
                $onRole = is_array($onRole) ? $onRole : [];
                if (array_keys($onRole) !== ['byPrivilege']) {
                    $found[] = sprintf("a rule about every privilege at once of '%s' for '%s'", $resource, $role);
                }

                $byPrivilege = $onRole['byPrivilege'] ?? [];
                foreach (is_array($byPrivilege) ? $byPrivilege : [] as $privilege => $rule) {
                    if ($rule !== ['type' => Permission::Allow, 'assert' => null]) {
                        $found[] = sprintf(
                            "'%s' of '%s' for '%s' is not a plain allow: %s",
                            $privilege,
                            $resource,
                            $role,
                            var_export($rule, true),
                        );
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Whether the list says this about this role on this resource itself,
     * rather than answering it through anything else.
     */
    private function isAllowedByARuleOfItsOwn(
        Permission $access,
        string $role,
        Resource $resource,
        Privilege $privilege,
    ): bool {
        $rules = $this->rulesOf($access);
        $byResource = is_array($rules['byResource'] ?? null) ? $rules['byResource'] : [];
        $onResource = is_array($byResource[$resource->value] ?? null) ? $byResource[$resource->value] : [];
        $byRole = is_array($onResource['byRole'] ?? null) ? $onResource['byRole'] : [];
        $onRole = is_array($byRole[$role] ?? null) ? $byRole[$role] : [];
        $byPrivilege = is_array($onRole['byPrivilege'] ?? null) ? $onRole['byPrivilege'] : [];

        return ($byPrivilege[$privilege->value] ?? null) === ['type' => Permission::Allow, 'assert' => null];
    }

    /** @return array<array-key, mixed> */
    private function rulesOf(Permission $access): array
    {
        $rules = new \ReflectionProperty(Permission::class, 'rules')->getValue($access);
        self::assertIsArray($rules);

        return $rules;
    }

    /**
     * Every Nette\Security\Permission the service keeps, wherever in its own
     * state it keeps it - so that moving the list to another property is not a
     * way of hiding it from this.
     *
     * @return list<Permission>
     */
    private function accessListsHeldBy(object $service): array
    {
        $found = [];
        foreach (new \ReflectionObject($service)->getProperties() as $property) {
            if (!$property->isStatic() && $property->isInitialized($service)) {
                $this->collectAccessLists($property->getValue($service), $found);
            }
        }

        return $found;
    }

    /** @param list<Permission> $found */
    private function collectAccessLists(mixed $value, array &$found): void
    {
        if ($value instanceof Permission) {
            $found[] = $value;

            return;
        }

        if (is_array($value)) {
            foreach ($value as $inside) {
                $this->collectAccessLists($inside, $found);
            }
        }
    }

    /**
     * A tenant and one account holding two roles in it: one that edits content
     * and is not given the administration - so the structure has a door to
     * work out from the section onto it - and one about a resource that falls
     * under nothing, so that more than one role is in the list.
     */
    private function installation(): void
    {
        $this->schema = Database::schemaFor(self::class);
        $this->container = Boot::coreAlone();
        Migrations::run($this->container);

        $bikes = Tenants::enter($this->container, 'Ammonite Bikes');

        $this->password = Random::generate(24, 'a-zA-Z0-9');
        $accounts = $this->container->getByType(Accounts::class);
        $alice = new User(
            'alice@example.com',
            $this->container->getByType(Passwords::class)->hash($this->password),
            'Alice Ammonite',
            new DateTimeImmutable('2026-09-12T08:00:00+00:00'),
        );
        $accounts->save($alice);

        $entityManager = $this->container->getByType(EntityManagerInterface::class);
        $editor = new Role(self::EDITOR, 'Content editor', ['app.administration.content:edit']);
        $keeper = new Role(self::KEEPER, 'Redirection keeper', ['app.redirection:force_redirect']);
        $entityManager->persist($editor);
        $entityManager->persist($keeper);
        $entityManager->persist(new Membership($bikes, $alice, $editor));
        $entityManager->persist(new Membership($bikes, $alice, $keeper));
        $entityManager->flush();
    }

    private function container(): Container
    {
        self::assertInstanceOf(Container::class, $this->container);

        return $this->container;
    }
}
