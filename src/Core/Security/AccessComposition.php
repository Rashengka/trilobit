<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

use Nette\Security\Permission;

/**
 * The one place an access list is put together, for both services that answer
 * from one.
 *
 * Trilobit\Core\Security\Authorizator and Trilobit\Core\Security\Permissions
 * cannot be one class - the first says why - but they have to answer from the
 * same list, and two copies of the loop that builds it are two loops, one of
 * which is the one that gets changed.
 *
 * **Nette is given plain allows and nothing else.** Every resource is
 * registered on its own, without what it falls under, and what
 * src/Core/Security/permissions.neon says a resource falls under is worked out
 * here: a piece granted on a resource is allowed, as a rule of its own, on
 * everything under it that offers the same privilege. That is exactly what
 * Nette's inheritance answered, so no answer changes. What changes is that no
 * answer depends on anything but the rule in front of it. A parent inside the
 * list is the route a right taken away from a section would come back by, from
 * the administration above it; a deny is a rule whose effect depends on the
 * order the rules were written in, which here would be the order of rows. So
 * neither is ever handed to Nette, and
 * tests/Integration/Security/AccessListsAreFlatTest asks that of the lists both
 * services really hold.
 *
 * What falls under each resource is worked out for every resource before any
 * role is read. A structure whose resources fall under each other in a circle
 * is therefore refused every time a list is built, as registering the parents
 * refused it, and not only once somebody holds a piece of it.
 */
final readonly class AccessComposition
{
    public function __construct(
        private PermissionStructure $structure,
    ) {}

    /**
     * A piece naming something this build does not offer is left out and the
     * rest of the role still holds. It was written by an earlier build, and
     * carrying the name as far as Nette would raise rather than deny - which
     * stops a person using the application instead of stopping them doing one
     * thing.
     *
     * A role whose code is empty is left out as well, for the same reason one
     * level up: Nette refuses an empty name with an exception, so one such row
     * would stop everybody in the business rather than refuse the one person
     * holding it. A code met a second time is the same role, and is read once.
     *
     * @param list<array{code: string, permissions: list<string>}> $roles the
     *     roles held here, with the pieces each is put together out of
     */
    public function compose(array $roles): Permission
    {
        $access = new Permission();

        $reach = [];
        foreach (Resource::cases() as $resource) {
            $access->addResource($resource->value);
            $reach[$resource->value] = [$resource, ...$this->structure->descendantsOf($resource)];
        }

        foreach ($roles as $role) {
            $code = $role['code'];
            if ($code === '' || $access->hasRole($code)) {
                continue;
            }

            $access->addRole($code);
            foreach ($role['permissions'] as $written) {
                $grant = Grant::parse($written);
                if (!$grant instanceof Grant || !$this->structure->offers($grant->resource, $grant->privilege)) {
                    continue;
                }

                foreach ($reach[$grant->resource->value] as $resource) {
                    if ($this->structure->offers($resource, $grant->privilege)) {
                        $access->allow($code, $resource->value, $grant->privilege->value);
                    }
                }
            }
        }

        return $access;
    }
}
