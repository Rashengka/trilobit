<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

use Nette\Security\Authorizator as NetteAuthorizator;
use Nette\Security\Permission;
use Trilobit\Core\Tenancy\Tenancy;
use Trilobit\Core\Tenancy\TenancyRefused;

/**
 * What one role may do here, answered for the framework rather than for us.
 *
 * It exists so that `$this->getUser()->isAllowed(...)` works. That is the
 * decision and it is worth stating plainly: we do not build a second way of
 * asking beside Nette's, because an application with two of them has one that
 * somebody has to be told about - and being told is what a gate cannot depend
 * on. Nette\Security\User asks this, once per role on the identity, and stays
 * exactly as it ships.
 *
 * **The question this answers is not the one Trilobit\Core\Security\Permissions
 * answers.** That one is about a person and reads which roles they hold; this
 * one is handed a role name and knows nothing about who is asking. They are two
 * halves of the same sentence, and they are not one class because the framework
 * splits them: the loop over the roles is inside Nette\Security\User.
 *
 * They also could not be one class if we wanted it. Nette\Security\User takes
 * an authorizator, and Permissions takes a Nette\Security\User - so an
 * authorizator that reached for Permissions would be a circular reference the
 * container refuses to build. The access list is therefore assembled twice,
 * from the same structure and the same rows, which is the cost of keeping the
 * framework's own shape - but by one Trilobit\Core\Security\AccessComposition,
 * so that the two lists cannot come to be put together differently.
 *
 * Three things about the way it answers are decisions:
 *
 * **A resource or a privilege that is not one of our enums is a LogicException
 * and never a quiet `false`.** Nette's own interface says `?string`, and a
 * string would be a question spelled by hand - which is the one thing the
 * enums exist to make impossible, because a misspelt privilege is answered "no"
 * for ever by nobody. The parameter types are widened to `string|...|null`
 * rather than to `mixed` for the same reason from the other side: an array, a
 * number or somebody else's object is refused by the engine before this class
 * has to have an opinion. `?string` is what the interface promises and cannot
 * be narrowed away.
 *
 * **A role name it does not know is `false`.** Nette hands over its own
 * vocabulary - `guest` for a visitor and `authenticated` for somebody signed in
 * with no roles of their own - and Nette\Security\Permission::checkRole()
 * raises "Role 'guest' does not exist." rather than answering. Passing those
 * through would turn every page a visitor opens into an error. It is also the
 * safe direction for a role that really has gone: not knowing a name is not
 * knowing of anything it may do.
 *
 * **The tenant comes from Trilobit\Core\Tenancy\Tenancy and is never a
 * parameter**, which is the same sentence Permissions says and must not be
 * softened here either. An access list is a triple with no business in it, so a
 * business added by the caller is one a caller can leave out, and the place it
 * is left out answers with somebody else's rights.
 */
final class Authorizator implements NetteAuthorizator
{
    /**
     * One access list per tenant this process has worked in.
     *
     * A request is served inside one business and asks its questions of one set
     * of rules; keeping the list is what makes a page asking twenty questions
     * one reading rather than twenty. That the rules cannot change underneath a
     * single request is the point rather than a side effect - a page that
     * allowed something at the top and refused it at the bottom would be
     * telling the truth twice and be useless both times.
     *
     * @var array<int, Permission>
     */
    private array $tenants = [];

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly PermissionStructure $structure,
        private readonly Memberships $memberships,
    ) {}

    /**
     * @param Resource|string|null $resource what is being asked about; anything
     *     but Trilobit\Core\Security\Resource is refused
     * @param Privilege|string|null $privilege what is being asked of it;
     *     anything but Trilobit\Core\Security\Privilege is refused
     *
     * @throws TenancyRefused when no tenant has been entered, because the
     *     answer would otherwise be somebody else's
     */
    public function isAllowed(
        ?string $role,
        string|Resource|null $resource,
        string|Privilege|null $privilege,
    ): bool {
        if (!$resource instanceof Resource || !$privilege instanceof Privilege) {
            throw new \LogicException(sprintf(
                'A permission question is asked with the two enums and nothing else; this one was asked with '
                    . "%s and %s. Write it as '%s::Something, %s::Something', so that a reader and "
                    . 'tests/Architecture/EveryPermissionQuestionIsPredefinedTest can both tell which pair it is.',
                get_debug_type($resource),
                get_debug_type($privilege),
                Resource::class,
                Privilege::class,
            ));
        }

        if (!$this->structure->offers($resource, $privilege)) {
            throw new \LogicException(sprintf(
                "Nothing may be answered about '%s' of '%s': %s does not offer that pair, so no role can hold it "
                    . 'and the answer would be no for everybody, for ever.',
                $privilege->value,
                $resource->value,
                PermissionStructure::FILE,
            ));
        }

        if ($role === null || $role === '') {
            return false;
        }

        $access = $this->inThisTenant($this->tenancy->current());

        return $access->hasRole($role)
            && $access->isAllowed($role, $resource->value, $privilege->value);
    }

    /**
     * The access list of one tenant: every resource this build has, and the
     * rules of the roles somebody holds here, put together by
     * Trilobit\Core\Security\AccessComposition exactly as
     * Trilobit\Core\Security\Permissions has its own put together.
     */
    private function inThisTenant(int $tenant): Permission
    {
        return $this->tenants[$tenant]
            ??= new AccessComposition($this->structure)->compose($this->memberships->rolesHeldHere());
    }
}
