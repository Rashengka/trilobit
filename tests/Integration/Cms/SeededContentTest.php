<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Cms;

use Contributte\Console\Application;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Trilobit\Cms\Application\Page\Pages;
use Trilobit\Cms\Domain\Menu\MenuItem;
use Trilobit\Cms\Domain\Menu\MenuRepository;
use Trilobit\Cms\Domain\Page\Page;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Config\Mode;
use Trilobit\Core\Domain\Navigation\Menu;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Navigation\Menus;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * What this module adds to `bin/trilobit app:seed`: pages and a menu for each
 * business the seed makes.
 *
 * The content is what somebody clicking through the site and its
 * administration needs to see - a page at the root, pages filed under a
 * category, a draft a visitor cannot open, and a menu with entries under an
 * entry. It is made for both businesses and differs between them by name, so
 * that a page of one turning up in the other is something a person would
 * notice rather than two identical pages nobody could tell apart. Here it is
 * asserted by asking each business for its pages and finding only its own.
 */
#[CoversNothing]
final class SeededContentTest extends TestCase
{
    private string $schema = '';

    /** What the mode variable held before this test set it - false when it was not set - or null while untouched. */
    private string|false|null $modeBefore = null;

    protected function tearDown(): void
    {
        if ($this->modeBefore !== null) {
            putenv($this->modeBefore === false ? Mode::VARIABLE : Mode::VARIABLE . '=' . $this->modeBefore);
            $this->modeBefore = null;
        }

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    /**
     * Both businesses have a page at the same address and each reads only its
     * own - the claim tenancy makes, seen in the content a person clicks
     * through.
     */
    public function testEachBusinessHasItsOwnPagesAtTheSameAddresses(): void
    {
        $container = $this->seeded();

        $this->enter($container, 'Ammonite Bikes');
        $ammonite = $this->titlesAndAddresses($container);

        $this->enter($container, 'Belemnite Books');
        $belemnite = $this->titlesAndAddresses($container);

        self::assertArrayHasKey('About Ammonite Bikes', $ammonite);
        self::assertArrayHasKey('About Belemnite Books', $belemnite);
        self::assertSame($ammonite['About Ammonite Bikes'], $belemnite['About Belemnite Books']);

        foreach (array_keys($ammonite) as $title) {
            self::assertStringNotContainsString('Belemnite', $title);
        }

        foreach (array_keys($belemnite) as $title) {
            self::assertStringNotContainsString('Ammonite', $title);
        }
    }

    /** Pages filed under a category answer under its address, which is how a deeper address is ever made. */
    public function testSomePagesAreFiledUnderACategory(): void
    {
        $container = $this->seeded();
        $this->enter($container, 'Ammonite Bikes');

        $addresses = $this->titlesAndAddresses($container);

        self::assertSame('help/delivery', $addresses['Delivery'] ?? null);
        self::assertSame('help/returns', $addresses['Returns'] ?? null);
    }

    /** One page is left a draft, so that there is something at an address a visitor is told is not there. */
    public function testOnePageIsADraft(): void
    {
        $container = $this->seeded();
        $this->enter($container, 'Ammonite Bikes');

        $drafts = array_values(array_filter(
            $container->getByType(Pages::class)->all(),
            static fn(Page $page): bool => !$page->isPublished(),
        ));

        self::assertCount(1, $drafts);
        self::assertSame('Summer sale', $drafts[0]->title());
    }

    /**
     * The main menu has entries at the top and one entry with two under it,
     * which is the arrangement the menu screens and the site's navigation
     * have to be able to show.
     */
    public function testTheMainMenuHasAnEntryWithEntriesUnderIt(): void
    {
        $container = $this->seeded();
        $this->enter($container, 'Belemnite Books');
        $menus = $container->getByType(MenuRepository::class);
        $main = $container->getByType(Menus::class)->named(Menu::MAIN);
        self::assertInstanceOf(Menu::class, $main, 'the seed arranged no main menu');

        $top = array_values(array_filter(
            $menus->visibleIn($main),
            static fn(MenuItem $entry): bool => !$entry->parent() instanceof MenuItem,
        ));
        self::assertSame(
            ['About us', 'Customer care', 'Elsewhere'],
            array_map(static fn(MenuItem $entry): string => $entry->label(), $top),
        );

        $under = [];
        foreach ($menus->all() as $entry) {
            $parent = $entry->parent();
            if ($parent instanceof MenuItem) {
                $under[$parent->label()][] = $entry->label();
            }
        }

        self::assertSame(['Customer care' => ['Delivery', 'Returns']], $under);
    }

    private function seeded(): Container
    {
        $this->schema = Database::schemaFor(self::class);

        // Stated rather than taken from the machine, which is production in a
        // clone with no .env. Only the mode is set, in the process environment
        // that wins over .env; everything else the build reads as a deployment
        // would, and this test reads none of it.
        $this->modeBefore ??= getenv(Mode::VARIABLE);
        putenv(Mode::VARIABLE . '=' . Mode::Dev->value);

        $container = Boot::container(
            ModuleList::of(['cms' => true, 'crm' => false, 'shop' => false], Bootstrap::rootDirectory()),
        );
        Migrations::run($container);

        $tester = new CommandTester($container->getByType(Application::class)->find('app:seed'));
        $status = $tester->execute([], ['interactive' => false, 'capture_stderr_separately' => true]);
        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay() . $tester->getErrorOutput());

        return $container;
    }

    private function enter(Container $container, string $name): void
    {
        $tenant = $container->getByType(EntityManagerInterface::class)
            ->getRepository(Tenant::class)
            ->findOneBy(['name' => $name]);
        self::assertInstanceOf(Tenant::class, $tenant, 'there is no business called ' . $name);

        Tenants::switchTo($container, $tenant);
    }

    /** @return array<string, string|null> where each page of the business the process is in answers, by its title */
    private function titlesAndAddresses(Container $container): array
    {
        $pages = $container->getByType(Pages::class);

        $found = [];
        foreach ($pages->all() as $page) {
            $found[$page->title()] = $pages->addressOf($page);
        }

        return $found;
    }
}
