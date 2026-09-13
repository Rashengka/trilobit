<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\User;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Tenancy\PartlyShared;

/**
 * A named set of permissions, granted to accounts rather than to people.
 *
 * The permissions are a list of strings in one column and not a table of their
 * own. The first version has a handful of them and they are chosen by ticking
 * boxes in a single form, which a list does perfectly well.
 * **Exit condition:** a table appears the moment the number of permissions
 * outgrows one form - see .ai/plans/01c-datovy-model.md.
 *
 * The code is what everything else refers to a role by, and the name is what a
 * person reads. They are separate so that renaming a role in the
 * administration cannot invalidate whatever was written against it.
 *
 * **A role is the application's, or one business's.** The application's belong
 * to no business and may be held in any of them - the owner's is one. A role a
 * business composes for itself (ofBusiness()) belongs to that business and is
 * held there alone; see Trilobit\Core\Domain\Tenancy\Membership. Reading follows
 * the same line: in a business the application's roles and its own are read,
 * never another's - see Trilobit\Core\Tenancy\TenantFilter.
 *
 * **The code is unique where it names one role**: among the roles of one
 * business, and among the application's. So two businesses may each compose an
 * `editor` without either of them knowing. The index cannot simply be over the
 * business and the code: the application's roles have no business, and MariaDB
 * does not hold one NULL to be equal to another, so it would let the
 * application's roles in twice without a word. It is over the code and
 * tenant_key instead, a column the database works out as the business, or 0
 * where there is none - no business is ever 0. The database works it out
 * rather than this class, so that a row written past the entity is held to it
 * all the same; Trilobit\Core\Security\Accounts::applicationRoleMadeIfMissing()
 * writes one exactly that way and stands on the refusal.
 *
 * The column is VIRTUAL rather than STORED because a stored one cannot be added
 * to an existing table without copying it, and a virtual one can be indexed
 * all the same. It is nullable because the server refuses NOT NULL on a
 * generated column; what it holds is never empty. It is mapped so that the
 * schema comparison sees it and generates it, and it is never written from
 * here.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_role')]
#[ORM\UniqueConstraint(name: 'uniq_role_code', columns: ['tenant_key', 'code'])]
#[PartlyShared(because: 'the roles the application defines - the owner\'s - are held in every business and belong to none, while a role a business composes for itself is that business\'s alone')]
class Role
{
    /**
     * The code of the one role the application defines rather than a
     * business: the owner of the business, which holds the whole of the
     * application - `app:*`, every section there is and every one added later.
     *
     * It is a code rather than a flag on the row because a code is what
     * everything else already refers to a role by, and it is written here once
     * because the owner is the only role allowed to hold the whole of the
     * application: Trilobit\Core\Security\AccessComposition drops that piece on
     * any other role. A second spelling of it anywhere would be a second place
     * deciding who owns a business.
     */
    public const string OWNER = 'owner';

    /**
     * The codes of the roles the application defines, which no business may
     * compose a role of its own under.
     *
     * The owner's is the reason: the whole of the application is honoured on
     * the owner's code alone, so a business's role under it would be an owner
     * nobody made one. And inside one business a code names one role - the
     * access list is keyed by it - so a business's role and the application's
     * under the same code would quietly become one of the two.
     */
    private const array OF_THE_APPLICATION = [self::OWNER];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The business this role belongs to, or null for a role of the application. */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: true)]
    private ?Tenant $tenant = null;

    /** Worked out by the database from the business; what the code is unique together with. */
    #[ORM\Column(
        name: 'tenant_key',
        type: Types::INTEGER,
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: 'INT AS (COALESCE(tenant_id, 0)) VIRTUAL',
        generated: 'ALWAYS',
    )]
    private ?int $tenantKey = null;

    /**
     * A role of the application, held in any business.
     *
     * @param list<string> $permissions what an account holding this role may
     *     do. Nothing in Core enforces one yet; the identity carries them so
     *     that a module can. See .ai/plans/08 decision D2.
     */
    public function __construct(
        #[ORM\Column(length: 64)]
        private string $code,
        #[ORM\Column(length: 255)]
        private string $name,
        #[ORM\Column(type: Types::JSON)]
        private array $permissions = [],
    ) {}

    /**
     * A role $business composes for itself, held there and nowhere else.
     *
     * A code the application defines is refused, in any case - the database
     * compares codes without it, and so does a person reading a list of roles.
     *
     * @param list<string> $permissions
     */
    public static function ofBusiness(Tenant $business, string $code, string $name, array $permissions = []): self
    {
        foreach (self::OF_THE_APPLICATION as $reserved) {
            if (mb_strtolower($code) === mb_strtolower($reserved)) {
                throw new \LogicException(sprintf(
                    '%s is the code of a role the application defines, so %s cannot compose a role of its own '
                        . 'under it: the application\'s role would be held here under the same name, and one of '
                        . 'the two would quietly stand for both. Choose another code.',
                    $code,
                    $business->name(),
                ));
            }
        }

        $role = new self($code, $name, $permissions);
        $role->tenant = $business;

        return $role;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** The business this role belongs to, or null for a role of the application. */
    public function business(): ?Tenant
    {
        return $this->tenant;
    }

    /**
     * Whether this role may be held in $business: a role of the application
     * anywhere, a role of a business in that business alone.
     *
     * The business is compared by its identifier as well as by the object, so
     * that the same row read twice - once detached, once through the manager -
     * is the same business.
     */
    public function mayBeHeldIn(Tenant $business): bool
    {
        if (!$this->tenant instanceof Tenant || $this->tenant === $business) {
            return true;
        }

        return $this->tenant->id() !== null && $this->tenant->id() === $business->id();
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissions;
    }

    /**
     * Replaces what this role is assembled from.
     *
     * It exists for the one role the application defines rather than a tenant:
     * the owner Trilobit\Core\Console\AccountCommand makes, which holds the
     * whole of the application. A row under that code may say something else -
     * edited by hand, or left by an earlier build - and a command that only
     * ever created the role would leave that account with rights nobody would
     * think to look for. Replacing rather than adding is what makes running the
     * command say what the role is now, rather than what it has ever been.
     *
     * @param list<string> $permissions
     */
    public function redefine(array $permissions): void
    {
        $this->permissions = $permissions;
    }
}
