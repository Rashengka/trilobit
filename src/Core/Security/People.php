<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Security\User as SignedIn;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Tenancy\Tenancy;

/**
 * Who belongs to the business this request is in, changed - and every rule
 * about changing it, in one place.
 *
 * **Every way in comes through here.** A page, a signal, a form posted by hand,
 * a command, a screen nobody has written yet: a guard written on one of them
 * is a guard the others do not have. So nothing here trusts that whoever calls
 * it asked first. Each act asks for its own privilege on
 * Trilobit\Core\Security\Resource::Account - adding, changing and removing are
 * three rights, not one - through Trilobit\Core\Security\Doorkeeper, the way a
 * gate above a page asks; the question is the two enums and never the name of
 * a method.
 *
 * **Nobody gives more than they have** (H5 in
 * .ai/plans/21-integrace-s-nette-a-model-roli.md). A role is given only by
 * somebody who may do everything it would let its holder do, worked out by
 * Trilobit\Core\Security\AccessComposition exactly as the access list is -
 * doors and wholes included - and asked of the giver pair by pair. Taking a
 * role away is held to the same line, because it is the other half of giving
 * it: somebody who could not have given the owner's role cannot take it from
 * the owner either, or the role in between could empty a business of whoever
 * outranks it.
 *
 * **Nobody changes their own membership.** Not to give themselves more, which
 * the line above would stop anyway, and not to take their own role away,
 * which would be the one way of losing the last owner without anybody else
 * being involved.
 *
 * **The last owner stays.** With the two rules above no single request can
 * remove the last owner - only an owner can take an owner's role away, and not
 * their own - but two owners removing each other at the same moment can: each
 * sees two owners and each removes the other. So the owners are read with a
 * locking read inside the transaction that removes, and the second request
 * waits for the first and then sees one owner, the one it was about to remove.
 * The lock is taken on the owners' rows of this business only, and only for
 * the length of one delete.
 *
 * A membership is removed outright rather than marked: the account stays, and
 * what it did stays attributed to it, but belonging to a business is a
 * relationship with nothing left to keep once it has ended. **Exit
 * condition:** the audit of .ai/plans/31-sprava-uzivatelu.md, PR 4, which
 * records who removed whom.
 *
 * The business is never an argument. It is the one this request is in -
 * Trilobit\Core\Tenancy\Tenancy - and every read of a membership or a role
 * goes through the tenant filter, so a membership or a role of another
 * business is not found, rather than found and refused.
 */
final readonly class People
{
    /** What anybody trying to change their own membership is told, wherever they try it. */
    private const string THEIR_OWN = 'This is you, and nobody changes their own membership - ask somebody else who '
        . 'manages people in this business.';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Doorkeeper $doorkeeper,
        private SignedIn $signedIn,
        private Tenancy $tenancy,
        private PermissionStructure $structure,
        private Accounts $accounts,
        private PasswordLinks $links,
    ) {}

    /**
     * The roles that may be held here and that the person asking may give,
     * by name - which is what a form offers, asked the way giving one is.
     *
     * @return list<Role>
     */
    public function rolesOffered(): array
    {
        $roles = $this->entityManager
            ->createQuery(sprintf('SELECT r FROM %s r ORDER BY r.name ASC, r.id ASC', Role::class))
            ->getResult();

        $offered = [];
        foreach (is_array($roles) ? $roles : [] as $role) {
            if ($role instanceof Role && $this->wouldGive($role) === null) {
                $offered[] = $role;
            }
        }

        return $offered;
    }

    /**
     * Adds whoever signs in with $email to this business, holding the role
     * $role.
     *
     * An address with no account gets one, without a password, and the
     * addition carries the token of the link that sets it - to be sent. An
     * address that has an account already, in another business, is given the
     * role and nothing else.
     *
     * @throws PeopleRefused
     */
    public function add(string $email, string $name, int $role): Addition
    {
        $this->refuseUnless($this->doorkeeper->mayDo(Resource::Account, Privilege::Add), 'add people to this business');
        $given = $this->roleHere($role);
        $this->refuseMoreThanTheyHold($given);

        $account = $this->accounts->withEmail($email);
        $hadAnAccount = $account instanceof User;

        if ($account instanceof User) {
            $this->refuseTheirOwn($account);

            if ($account->isLandlord()) {
                throw new PeopleRefused(sprintf(
                    '%s administers the installation. Giving that account a role in a business is a decision of its '
                        . 'own, made from the command line with bin/trilobit app:account, and not from here.',
                    $account->email(),
                ));
            }

            $this->refuseWhatTheyHold($account, $given);
        } else {
            $account = User::invited($email, $name, new DateTimeImmutable());
            $this->entityManager->persist($account);
        }

        $this->entityManager->persist(new Membership($this->business(), $account, $given));
        $this->entityManager->flush();

        return new Addition($account, $hadAnAccount, $hadAnAccount ? null : $this->links->issue($account));
    }

    /**
     * Gives $person, who belongs here already, the role $role as well.
     *
     * @throws PeopleRefused
     */
    public function give(int $person, int $role): void
    {
        $this->refuseUnless($this->doorkeeper->mayDo(Resource::Account, Privilege::Edit), 'change what people hold in this business');
        $account = $this->personHere($person);
        $this->refuseTheirOwn($account);

        $given = $this->roleHere($role);
        $this->refuseMoreThanTheyHold($given);
        $this->refuseWhatTheyHold($account, $given);

        $this->entityManager->persist(new Membership($this->business(), $account, $given));
        $this->entityManager->flush();
    }

    /**
     * Takes one role held here away from whoever holds it - the membership,
     * outright. See the class for the three rules, and for why the owners are
     * read with a lock.
     *
     * @throws PeopleRefused
     */
    public function remove(int $membership): void
    {
        $this->refuseUnless($this->doorkeeper->mayDo(Resource::Account, Privilege::Delete), 'remove people from this business');

        $held = $this->entityManager
            ->createQuery(sprintf('SELECT m, r, u FROM %s m JOIN m.role r JOIN m.user u WHERE m.id = :id', Membership::class))
            ->setParameter('id', $membership)
            ->getOneOrNullResult();
        if (!$held instanceof Membership) {
            throw new PeopleRefused('That role is not held by anybody in this business.');
        }

        $refusal = $this->whyNotTakeAway($held);
        if ($refusal !== null) {
            throw new PeopleRefused($refusal);
        }

        $this->removeUnlessTheLastOwner($membership, $held->role());
        $this->entityManager->detach($held);
    }

    /**
     * Why the person asking could not take $held away - null when they could,
     * as far as anybody but the last owner is concerned. The one definition
     * both remove() and a page deciding whether to draw the button use.
     */
    public function whyNotTakeAway(Membership $held): ?string
    {
        if (!$this->doorkeeper->mayDo(Resource::Account, Privilege::Delete)) {
            return 'You may not remove people from this business.';
        }

        if ($this->isTheirOwn($held->user())) {
            return self::THEIR_OWN;
        }

        return $this->wouldGive($held->role());
    }

    /**
     * A new link for $person to set their password with, to be sent again -
     * the earlier one stops opening. Only for somebody who has not set a
     * password yet: changing somebody else's is a different thing, and not
     * this one (O5).
     *
     * @throws PeopleRefused
     */
    public function invitationFor(int $person): string
    {
        $this->refuseUnless($this->doorkeeper->mayDo(Resource::Account, Privilege::Add), 'invite people to this business');
        $account = $this->personHere($person);
        $this->refuseTheirOwn($account);

        if ($account->hasPassword()) {
            throw new PeopleRefused(sprintf(
                '%s has set a password already, so there is no invitation to send again.',
                $account->name(),
            ));
        }

        return $this->links->issue($account);
    }

    /**
     * Why the person asking could not change what $person holds here - null
     * when they could. For a page deciding what to offer; the acts ask again.
     */
    public function whyNotChange(int $person): ?string
    {
        if ($this->signedIn->getId() === $person) {
            return self::THEIR_OWN;
        }

        foreach ($this->rolesHeldBy($person) as $role) {
            $refusal = $this->wouldGive($role);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        return null;
    }

    /**
     * The roles $person holds here.
     *
     * @return list<Role>
     */
    public function rolesHeldBy(int $person): array
    {
        return array_map(
            static fn(Membership $membership): Role => $membership->role(),
            $this->membershipsOf($person),
        );
    }

    /**
     * The memberships $person has here, by the name of the role.
     *
     * @return list<Membership>
     */
    public function membershipsOf(int $person): array
    {
        $rows = $this->entityManager
            ->createQuery(sprintf(
                'SELECT m, r FROM %s m JOIN m.role r WHERE m.user = :person ORDER BY r.name ASC, r.id ASC',
                Membership::class,
            ))
            ->setParameter('person', $person)
            ->getResult();

        return array_values(array_filter(is_array($rows) ? $rows : [], static fn(mixed $row): bool => $row instanceof Membership));
    }

    /**
     * The one delete, with the owners of this business locked first. The
     * tables and columns are the mapping's, read off it rather than written
     * out a second time; the business is said outright because the tenant
     * filter covers queries of the entity manager and this is a statement on
     * its connection.
     */
    private function removeUnlessTheLastOwner(int $membership, Role $role): void
    {
        $memberships = $this->entityManager->getClassMetadata(Membership::class);
        $roles = $this->entityManager->getClassMetadata(Role::class);
        $table = $memberships->getTableName();
        $tenantColumn = $memberships->getSingleAssociationJoinColumnName('tenant');
        $business = $this->tenancy->current();

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $owners = $connection->fetchFirstColumn(
                sprintf(
                    'SELECT m.id FROM %s m JOIN %s r ON r.id = m.%s WHERE m.%s = ? AND r.%s = ? AND r.%s IS NULL FOR UPDATE',
                    $table,
                    $roles->getTableName(),
                    $memberships->getSingleAssociationJoinColumnName('role'),
                    $tenantColumn,
                    $roles->getColumnName('code'),
                    $roles->getSingleAssociationJoinColumnName('tenant'),
                ),
                [$business, Role::OWNER],
            );
            $owners = array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $owners);

            if (in_array($membership, $owners, true) && count($owners) === 1) {
                throw new PeopleRefused(sprintf(
                    'This is the last owner of this business, and a business always has one. Make somebody else '
                        . '%s first.',
                    $role->name(),
                ));
            }

            $connection->delete($table, ['id' => $membership, $tenantColumn => $business]);
            $connection->commit();
        } catch (\Throwable $failed) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $failed;
        }
    }

    /**
     * The question is asked where each act is, spelled out as its two enums,
     * so that it is one Trilobit\Tests\Architecture\PermissionQuestions can
     * read; this only turns the answer into the refusal.
     */
    private function refuseUnless(bool $allowed, string $what): void
    {
        if (!$allowed) {
            throw new PeopleRefused(sprintf('You may not %s.', $what));
        }
    }

    private function refuseTheirOwn(User $account): void
    {
        if ($this->isTheirOwn($account)) {
            throw new PeopleRefused(self::THEIR_OWN);
        }
    }

    private function isTheirOwn(User $account): bool
    {
        return $account->id() !== null && $this->signedIn->getId() === $account->id();
    }

    private function refuseMoreThanTheyHold(Role $role): void
    {
        $refusal = $this->wouldGive($role);
        if ($refusal !== null) {
            throw new PeopleRefused($refusal);
        }
    }

    /**
     * Null when the person asking may do everything $role lets its holder do
     * here; otherwise the sentence saying what they lack.
     */
    private function wouldGive(Role $role): ?string
    {
        foreach ($this->pairsOf($role) as [$resource, $privilege]) {
            if (!$this->doorkeeper->mayDo($resource, $privilege)) {
                return sprintf(
                    '%s lets its holder %s %s, which you may not, so it is not yours to give or to take away.',
                    $role->name(),
                    $privilege->value,
                    PermissionStructure::nameOf($resource),
                );
            }
        }

        return null;
    }

    /**
     * Every pair $role lets its holder do, worked out the way the access list
     * of a business is - see Trilobit\Core\Security\AccessComposition.
     *
     * @return list<array{ResourceName, Privilege}>
     */
    private function pairsOf(Role $role): array
    {
        $code = $role->code();
        if ($code === '') {
            return [];
        }

        $access = new AccessComposition($this->structure)->compose([['code' => $code, 'permissions' => $role->permissions()]]);

        $pairs = [];
        foreach ($this->structure->everyPair() as $pair) {
            $privilege = $pair->privilege;
            if (!$privilege instanceof Privilege) {
                continue;
            }

            if ($access->isAllowed($code, PermissionStructure::nameOf($pair->resource), $privilege->value)) {
                $pairs[] = [$pair->resource, $privilege];
            }
        }

        return $pairs;
    }

    private function refuseWhatTheyHold(User $account, Role $role): void
    {
        $holding = $this->entityManager
            ->createQuery(sprintf('SELECT COUNT(m.id) FROM %s m WHERE m.user = :account AND m.role = :role', Membership::class))
            ->setParameter('account', $account)
            ->setParameter('role', $role)
            ->getSingleScalarResult();

        if (is_numeric($holding) && (int) $holding > 0) {
            throw new PeopleRefused(sprintf('%s holds %s here already.', $account->name(), $role->name()));
        }
    }

    /** A role that may be held here, read through the tenant filter: the application's, or this business's own. */
    private function roleHere(int $id): Role
    {
        $role = $this->entityManager
            ->createQuery(sprintf('SELECT r FROM %s r WHERE r.id = :id', Role::class))
            ->setParameter('id', $id)
            ->getOneOrNullResult();

        if (!$role instanceof Role || !$role->mayBeHeldIn($this->business())) {
            throw new PeopleRefused('There is no such role in this business.');
        }

        return $role;
    }

    /** An account that holds a role here. */
    private function personHere(int $id): User
    {
        $account = $this->accounts->withId($id);
        if (!$account instanceof User || $this->membershipsOf($id) === []) {
            throw new PeopleRefused('That person does not belong to this business.');
        }

        return $account;
    }

    private function business(): Tenant
    {
        return $this->entityManager->find(Tenant::class, $this->tenancy->current())
            ?? throw new \LogicException('The business this request is in cannot be read back.');
    }
}
