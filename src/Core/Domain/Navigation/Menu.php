<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\Navigation;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Navigation\Composition;

/**
 * One menu of a business's site, and what the business saved about how it is
 * put together (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3, decided
 * 2026-09-13).
 *
 * **What the menu is made of is not here.** Its entries come from the
 * contributors the build has - the way home, the sections of the modules, the
 * entries somebody arranged in the content administration - and Core knows
 * none of them by name. What is here is the one thing no contributor can say
 * for itself, because a contributor from code has no row to say it on: the
 * order they stand in and which of them are drawn. Nothing saved is the default
 * from code, and that is what a new menu starts as.
 *
 * It is an entity rather than a name in a column because it has something to
 * say about itself. A module's rows point at it with a foreign key - Core's
 * tables are the ones a module may point at - so a menu cannot be misspelt
 * into existence by an entry that names it.
 *
 * **Not yet published or drafted.** A structure that has to be prepared before
 * it is shown wants that, and categories will have it; a menu gets the same
 * mechanism at the same time. *Until* publishing categories is built
 * (.ai/plans/16-soft-delete.md), what is saved here is live when it is saved.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_menu')]
#[ORM\UniqueConstraint(name: 'uniq_menu_name', columns: ['tenant_id', 'name'])]
class Menu
{
    /** The menu the site's own navigation is drawn from; a business may keep others beside it. */
    public const string MAIN = 'main';

    public const int MAX_NAME_LENGTH = 32;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The arrangement the business saved, as Composition::toArray() writes it,
     * or null for the default from code.
     *
     * @var array<mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $composition = null;

    public function __construct(
        /** Whose menu this is. */
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\Column(length: self::MAX_NAME_LENGTH)]
        private string $name,
    ) {}

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function composition(): ?Composition
    {
        return $this->composition === null ? null : Composition::fromArray($this->composition);
    }

    public function recompose(Composition $composition): void
    {
        $this->composition = $composition->toArray();
    }

    public function useTheDefault(): void
    {
        $this->composition = null;
    }
}
