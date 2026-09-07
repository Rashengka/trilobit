<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

use Doctrine\ORM\EntityManagerInterface;
use Nette\Security\Permission;
use Nette\Security\User as SignedIn;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Tenancy\Tenancy;
use Trilobit\Core\Tenancy\TenancyRefused;

/**
 * Whether the person making this request may do this, here.
 *
 * The whole design is in the word *here*, and in the fact that no caller says
 * it. An access list is a triple - role, resource, privilege - and a tenant is
 * not in it, so it is the kind of thing that gets added from the outside and
 * then forgotten in one place; and the place it is forgotten answers "yes"
 * with somebody else's rights. There is no argument for it and no optional
 * one: this service is given Trilobit\Core\Tenancy\Tenancy and asks it, and
 * Tenancy has no default and no "no tenant" mode, so a question asked before a
 * tenant was entered raises Trilobit\Core\Tenancy\TenancyRefused. A decision
 * that cannot be taken cannot be taken wrongly, which is worth more than
 * taking it correctly everywhere - "everywhere" includes the places that do
 * not exist yet.
 *
 * **Both arguments are enums, and that is the only reason a typo is ever
 * seen.** Nette raises on a resource it does not know and says nothing at all
 * about a privilege - there is no checkPrivilege() in the class - so a
 * misspelt privilege would be a question with a permanent quiet answer of
 * "no". Here a pair the structure does not offer is a LogicException, in the
 * same breath as a pair spelled with a string would not have been.
 *
 * **The list is built for one tenant.** Its resources and its inheritance come
 * from the shared structure, its rules from the roles held in this tenant, and
 * it is kept for as long as the process stays in that tenant. Nothing from
 * another tenant is in it, so a rule cannot reach across even if the same role
 * name is held in both.
 *
 * **Only allowing is expressible, and that follows from the data rather than
 * from taste.** A role carries a list of the pieces it was assembled from;
 * there is no way to write down a piece being taken away, so there are no deny
 * rules and no precedence between two roles to decide. Somebody holding two
 * roles is allowed whatever either of them allows.
 *
 * What is deliberately not read is the permission snapshot on the identity
 * (Trilobit\Core\Security\Identity). That snapshot is per account and has no
 * tenant in it, and it is the thing this class exists not to be. Reading the
 * roles held here costs one query per tenant per request and is right the
 * moment somebody's role changes, rather than the next time they sign in.
 */
final class Permissions
{
    /**
     * One access list per tenant this process has worked in, and the roles
     * each person holds there, from the one query both were made out of.
     *
     * They are kept together because they have to agree: asking Nette about a
     * role it was not given raises rather than answers, so a person's role
     * codes and the list they are asked of are two halves of one reading.
     *
     * @var array<int, array{access: Permission, roles: array<int, list<string>>}>
     */
    private array $tenants = [];

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly PermissionStructure $structure,
        private readonly EntityManagerInterface $entityManager,
        private readonly SignedIn $signedIn,
    ) {}

    /**
     * @throws TenancyRefused when no tenant has been entered, because the
     *     answer would otherwise be somebody else's
     */
    public function isAllowed(Resource $resource, Privilege $privilege): bool
    {
        if (!$this->structure->offers($resource, $privilege)) {
            throw new \LogicException(sprintf(
                "Nothing may be answered about '%s' of '%s': %s does not offer that pair, so no role can hold it "
                    . 'and the answer would be no for everybody, for ever.',
                $privilege->value,
                $resource->value,
                PermissionStructure::FILE,
            ));
        }

        $tenant = $this->tenancy->current();

        $person = $this->signedIn->getId();
        if (!$this->signedIn->isLoggedIn() || !is_int($person)) {
            return false;
        }

        $held = $this->inThisTenant($tenant);
        foreach ($held['roles'][$person] ?? [] as $code) {
            if ($held['access']->isAllowed($code, $resource->value, $privilege->value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The access list of one tenant, and who holds which role in it.
     *
     * @return array{access: Permission, roles: array<int, list<string>>}
     */
    private function inThisTenant(int $tenant): array
    {
        if (isset($this->tenants[$tenant])) {
            return $this->tenants[$tenant];
        }

        $access = new Permission();
        $this->structure->addResourcesTo($access);

        $roles = [];
        foreach ($this->membershipsHere() as [$role, $person]) {
            $code = $role->code();

            if (!$access->hasRole($code)) {
                $access->addRole($code);
                foreach ($role->permissions() as $written) {
                    $piece = Grant::parse($written);
                    if ($piece instanceof Grant && $this->structure->offers($piece->resource, $piece->privilege)) {
                        $access->allow($code, $piece->resource->value, $piece->privilege->value);
                    }
                }
            }

            $roles[$person][] = $code;
        }

        return $this->tenants[$tenant] = ['access' => $access, 'roles' => $roles];
    }

    /**
     * Who holds which role in the tenant the process is in, as pairs.
     *
     * One query and no accounts loaded: the account is only ever compared with
     * the one signing in, so its identifier is asked for by name rather than
     * reached through the association, which would be a load of a row nothing
     * reads. The role is fetched with the membership for the same reason in
     * reverse - it is read from every row, so leaving it lazy would be a query
     * per membership.
     *
     * The tenant is not in the statement because it is not this class's to
     * add: the filter over core_tenant_membership puts it there, which is the
     * same sentence every other read of a tenanted table says.
     *
     * @return list<array{Role, int}>
     */
    private function membershipsHere(): array
    {
        $rows = $this->entityManager
            ->createQuery(sprintf(
                'SELECT m, r, IDENTITY(m.user) AS person FROM %s m JOIN m.role r',
                Membership::class,
            ))
            ->getResult();

        $held = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && $row[0] instanceof Membership && is_numeric($row['person'] ?? null)) {
                $held[] = [$row[0]->role(), (int) $row['person']];
            }
        }

        return $held;
    }
}
