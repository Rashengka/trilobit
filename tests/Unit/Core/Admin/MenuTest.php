<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Admin\Menu\Menu;
use Trilobit\Core\Admin\Menu\MenuItem;
use Trilobit\Core\Admin\Menu\MenuProvider;

#[CoversClass(Menu::class)]
#[CoversClass(MenuItem::class)]
final class MenuTest extends TestCase
{
    public function testAMenuWithoutProvidersIsEmpty(): void
    {
        self::assertSame([], new Menu([])->items());
    }

    public function testEveryProviderContributes(): void
    {
        $menu = new Menu([
            $this->provider(new MenuItem('Pages', 'Cms:Admin:Page:default')),
            $this->provider(new MenuItem('Products', 'Shop:Admin:Product:default')),
        ]);

        self::assertSame(['Pages', 'Products'], array_map(
            static fn(MenuItem $item): string => $item->label,
            $menu->items(),
        ));
    }

    public function testItemsAreOrderedByWeightAndThenByLabel(): void
    {
        $menu = new Menu([
            $this->provider(new MenuItem('Zebra', 'Core:Admin:Zebra:default', 10), new MenuItem('Alpha', 'Core:Admin:Alpha:default', 10), new MenuItem('First', 'Core:Admin:First:default', 5)),
        ]);

        self::assertSame(['First', 'Alpha', 'Zebra'], array_map(
            static fn(MenuItem $item): string => $item->label,
            $menu->items(),
        ));
    }

    public function testAModuleIsTheFirstSegmentOfTheDestinationLowerCased(): void
    {
        self::assertSame('cms', new MenuItem('Pages', 'Cms:Admin:Page:default')->module());
        self::assertSame('shop', new MenuItem('Shop', 'Shop:Front:Status:default')->module());
    }

    /**
     * The signpost's own source: exactly the entries of one module, in the
     * same order the bar itself draws them in - see
     * src/Core/Presentation/Admin/AdminPresenter.php and
     * .ai/plans/10-menu-submenu-a-rozcestniky.md, M2.
     */
    public function testItemsOfReturnsExactlyOneModulesEntriesInMenuOrder(): void
    {
        $menu = new Menu([
            $this->provider(
                new MenuItem('Menus', 'Cms:Admin:Menu:default', 20),
                new MenuItem('Pages', 'Cms:Admin:Page:default', 10),
            ),
            $this->provider(new MenuItem('Products', 'Shop:Admin:Product:default')),
        ]);

        self::assertSame(['Pages', 'Menus'], array_map(
            static fn(MenuItem $item): string => $item->label,
            $menu->itemsOf('cms'),
        ));
    }

    /** A module with nothing in the bar has nothing for a signpost either - the case M2 turns into no page at all. */
    public function testItemsOfAModuleWithNoEntriesIsEmpty(): void
    {
        $menu = new Menu([
            $this->provider(new MenuItem('Products', 'Shop:Admin:Product:default')),
        ]);

        self::assertSame([], $menu->itemsOf('cms'));
    }

    private function provider(MenuItem ...$items): MenuProvider
    {
        return new readonly class (array_values($items)) implements MenuProvider {
            /** @param list<MenuItem> $items */
            public function __construct(private array $items) {}

            public function provide(): iterable
            {
                return $this->items;
            }
        };
    }
}
