<?php

declare(strict_types=1);

namespace Trilobit\Core\Navigation;

use Doctrine\ORM\EntityManagerInterface;
use Trilobit\Core\Domain\Navigation\Menu;
use Trilobit\Core\Tenancy\Tenancy;

/**
 * The menus of the business this request belongs to.
 *
 * A menu is looked up by its name, and the business is the tenant filter's to
 * add, the way it is for every row somebody arranges. There is no default
 * menu row: a menu exists from the moment something is written to it - an
 * entry arranged into it, or an arrangement saved for it - and before that
 * it is simply the default from code.
 */
final readonly class Menus
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Tenancy $tenancy,
    ) {}

    public function named(string $name): ?Menu
    {
        return $this->entityManager->getRepository(Menu::class)->findOneBy(['name' => $name]);
    }

    /** The menu called $name, made for this business on the spot when it has none yet. */
    public function namedOrNew(string $name): Menu
    {
        $menu = $this->named($name);
        if ($menu instanceof Menu) {
            return $menu;
        }

        $menu = new Menu($this->tenancy->tenant(), $name);
        $this->save($menu);

        return $menu;
    }

    public function save(Menu $menu): void
    {
        $this->entityManager->persist($menu);
        $this->entityManager->flush();
    }
}
