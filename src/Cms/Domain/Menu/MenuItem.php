<?php

declare(strict_types=1);

namespace Trilobit\Cms\Domain\Menu;

use Doctrine\ORM\Mapping as ORM;
use Trilobit\Cms\Domain\Page\Page;
use Trilobit\Core\Domain\Tenancy\Tenant;

/**
 * One entry of a menu somebody arranged: what it is called, where it leads,
 * and what it sits under.
 *
 * **Where it leads is a kind and one value, never three nullable columns.** A
 * menu is the place where the parts of an installation are listed side by
 * side, so an entry has to be able to name a page of a module other than this
 * one - and a foreign key across that boundary is exactly what stops a module
 * from being switched off (.ai/plans/01-architektura.md §3.5). So an entry into
 * another module is a presenter's name held as text, and only an entry into
 * this module's own pages is an association.
 *
 * **The consequence is that an entry may point at nothing.** The module it
 * names can be switched off, and the row stays behind waiting for it to come
 * back, the way a row in the register of public addresses does. Whoever draws
 * the menu therefore has to ask whether this build can draw the entry, and
 * leave it out when it cannot - see
 * Trilobit\Core\Presentation\Link\Destinations. Drawing it anyway would produce
 * a link to a page that does not exist, which is worse than an entry missing:
 * it looks like a menu that works until somebody uses it.
 *
 * The constructor is private and the three ways in are named, so that the kind
 * and the value cannot disagree. An entry whose kind says Route and whose
 * target is empty is a shape this class simply has no way to make.
 *
 * A menu belongs to a tenant, like everything else somebody arranges.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cms_menu_item')]
class MenuItem
{
    /** The menu the site's own navigation is drawn from; an installation may arrange others beside it. */
    public const string MAIN = 'main';

    public const int MAX_MENU_LENGTH = 32;

    public const int MAX_LABEL_LENGTH = 191;

    /** Long enough for an absolute address, which is the longest of the three kinds. */
    public const int MAX_TARGET_LENGTH = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    private function __construct(
        /** Whose menu this belongs to. */
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        /** Which menu of that tenant, so that one installation may arrange more than one. */
        #[ORM\Column(length: self::MAX_MENU_LENGTH)]
        private string $menu,
        #[ORM\Column(length: self::MAX_LABEL_LENGTH)]
        private string $label,
        #[ORM\Column(length: 16, enumType: MenuTarget::class)]
        private MenuTarget $targetType,
        /** An address or a presenter's name; empty for an entry pointing at a page of this module. */
        #[ORM\Column(length: self::MAX_TARGET_LENGTH)]
        private string $target,
        /**
         * The page this entry leads to, for the one kind that may hold a
         * foreign key. Deleting the page deletes the entry, because an entry
         * leading nowhere in particular is worse than no entry.
         */
        #[ORM\ManyToOne(targetEntity: Page::class)]
        #[ORM\JoinColumn(onDelete: 'CASCADE')]
        private ?Page $page = null,
        /** What this entry hangs under, or null for one at the top of its menu. */
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(onDelete: 'CASCADE')]
        private ?self $parent = null,
        /** Where it sits among its siblings; ties are settled by the label, so the order is never arbitrary. */
        #[ORM\Column]
        private int $position = 0,
        #[ORM\Column]
        private bool $visible = true,
    ) {}

    public static function toPage(Tenant $tenant, string $menu, string $label, Page $page, int $position = 0): self
    {
        return new self($tenant, $menu, $label, MenuTarget::Page, '', $page, null, $position);
    }

    public static function toUrl(Tenant $tenant, string $menu, string $label, string $url, int $position = 0): self
    {
        return new self($tenant, $menu, $label, MenuTarget::Url, $url, null, null, $position);
    }

    /** @param string $destination a presenter and an action, as a link names them: `Shop:Front:Catalog:default`. */
    public static function toRoute(Tenant $tenant, string $menu, string $label, string $destination, int $position = 0): self
    {
        return new self($tenant, $menu, $label, MenuTarget::Route, $destination, null, null, $position);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function menu(): string
    {
        return $this->menu;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function targetType(): MenuTarget
    {
        return $this->targetType;
    }

    public function target(): string
    {
        return $this->target;
    }

    public function page(): ?Page
    {
        return $this->page;
    }

    public function parent(): ?self
    {
        return $this->parent;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function callItem(string $label): void
    {
        $this->label = $label;
    }

    public function pointAtPage(Page $page): void
    {
        $this->targetType = MenuTarget::Page;
        $this->target = '';
        $this->page = $page;
    }

    public function pointAtUrl(string $url): void
    {
        $this->targetType = MenuTarget::Url;
        $this->target = $url;
        $this->page = null;
    }

    public function pointAtRoute(string $destination): void
    {
        $this->targetType = MenuTarget::Route;
        $this->target = $destination;
        $this->page = null;
    }

    /**
     * Files this entry under another one.
     *
     * An entry cannot be put under itself; anything deeper - a loop through
     * two entries - is refused where the arrangement is made rather than here,
     * because this object can only see one link of it.
     */
    public function fileUnder(?self $parent): void
    {
        $this->parent = $parent === $this ? null : $parent;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    public function show(): void
    {
        $this->visible = true;
    }

    public function hide(): void
    {
        $this->visible = false;
    }
}
