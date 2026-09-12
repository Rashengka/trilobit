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
 * **What a role's pieces mean is decided here, and it is four sentences.**
 *
 * - A pair means that pair. Nothing concrete is inherited downwards: opening
 *   the administration is not reading every section of it, which is what
 *   made a door into a bundle.
 * - Any right on a resource opens everything it falls under - `view` on each
 *   of its ancestors in src/Core/Security/permissions.neon. Somebody who may
 *   work in a section may get to it.
 * - The whole of a resource, `x:*`, is every privilege of it and of
 *   everything under it. Granted, it is honoured only where the structure
 *   offers the resource as a bundle and is dropped elsewhere, as an outdated
 *   piece is; denied, it is honoured everywhere.
 * - A denial wins over everything, doors included: what is granted, less what
 *   is denied, then the doors of what is left, then less what is denied
 *   again. Without the second subtraction the door into the administration
 *   would come back from any section, and taking it away would be a denial
 *   nobody could write.
 *
 * All four are sets and the operations on them are union and difference, so
 * the order pieces are written in cannot change an answer. That is the reason
 * a denial is subtracted here instead of being handed to Nette, where the rule
 * written last wins and "last" would be the order of rows.
 *
 * **Nette is given plain allows and nothing else.** Every resource is
 * registered on its own, without what it falls under, and the list holds a
 * rule of its own for every pair that is left. A parent inside the list is the
 * route a right taken away from a section would come back by; a deny is a rule
 * whose effect depends on the order the rules were written in. So neither is
 * ever handed to Nette, and tests/Integration/Security/AccessListsAreFlatTest
 * asks that of the lists both services really hold.
 *
 * What falls under each resource is worked out for every resource before any
 * role is read. A structure whose resources fall under each other in a circle
 * is therefore refused every time a list is built, and not only once somebody
 * holds a piece of it.
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
     * thing. The whole of a resource offering no bundle is left out the same
     * way: a doubt takes the right away.
     *
     * A role whose code is empty is left out as well, for the same reason one
     * level up: Nette refuses an empty name with an exception, so one such row
     * would stop everybody in the business rather than refuse the one person
     * holding it. A code met a second time is the same role, and is read once.
     *
     * Denials are a role's own and take away from that role. No role carries
     * any yet; they arrive with the duties a role is assembled from.
     *
     * @param list<array{code: string, permissions: list<string>, denials?: list<string>}> $roles the
     *     roles held here, with the pieces each is put together out of and the
     *     pieces taken away from it
     */
    public function compose(array $roles): Permission
    {
        $access = new Permission();

        $reach = [];
        $above = [];
        foreach (Resource::cases() as $resource) {
            $access->addResource($resource->value);
            $reach[$resource->value] = [$resource, ...$this->structure->descendantsOf($resource)];
            $above[$resource->value] = $this->structure->ancestorsOf($resource);
        }

        foreach ($roles as $role) {
            $code = $role['code'];
            if ($code === '' || $access->hasRole($code)) {
                continue;
            }

            $access->addRole($code);

            $denied = $this->pairsOf($role['denials'] ?? [], $reach, wholeNeedsABundle: false);
            $held = array_diff_key($this->pairsOf($role['permissions'], $reach, wholeNeedsABundle: true), $denied);

            foreach ($held as [$resource]) {
                foreach ($above[$resource->value] as $ancestor) {
                    $held[$ancestor->value . ':' . Privilege::View->value] = [$ancestor, Privilege::View];
                }
            }

            foreach (array_diff_key($held, $denied) as [$resource, $privilege]) {
                $access->allow($code, $resource->value, $privilege->value);
            }
        }

        return $access;
    }

    /**
     * Every pair the written pieces stand for, keyed by the pair so that a
     * pair met twice is one pair and a difference of two sets is a
     * difference of keys.
     *
     * @param list<string> $written
     * @param array<string, non-empty-list<Resource>> $reach each resource and
     *     everything under it, by the resource's own value
     *
     * @return array<string, array{Resource, Privilege}>
     */
    private function pairsOf(array $written, array $reach, bool $wholeNeedsABundle): array
    {
        $pairs = [];
        foreach ($written as $piece) {
            $grant = Grant::parse($piece);
            if (!$grant instanceof Grant) {
                continue;
            }

            $privilege = $grant->privilege;
            if ($privilege instanceof Privilege) {
                if ($this->structure->offers($grant->resource, $privilege)) {
                    $pairs[$grant->resource->value . ':' . $privilege->value] = [$grant->resource, $privilege];
                }

                continue;
            }

            if ($wholeNeedsABundle && !$this->structure->offersBundle($grant->resource)) {
                continue;
            }

            foreach ($reach[$grant->resource->value] as $resource) {
                foreach ($this->structure->privilegesOf($resource) as $offered) {
                    $pairs[$resource->value . ':' . $offered->value] = [$resource, $offered];
                }
            }
        }

        return $pairs;
    }
}
