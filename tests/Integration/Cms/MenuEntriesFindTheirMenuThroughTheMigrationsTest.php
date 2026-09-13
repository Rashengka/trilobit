<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Cms;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Nette\DI\Container;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Migrations\Version20260913063908;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * The entries arranged while a menu was only a name find their menu when the
 * migrations run, and find their name again when the migrations are taken back
 * (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3, decided 2026-09-13).
 *
 * An entry used to name its menu in a column of text, and now points at a row
 * of Core's. The change is three migrations - the new column beside the old
 * one, the rows made and pointed at, the old column gone and the new one
 * required - and the middle one is the only one no generator writes.
 *
 * **A suite that migrates an empty database never sees it.** An empty table
 * satisfies NOT NULL whatever the data step does or fails to do, so every other
 * test here would pass without it - and the first installation with an entry
 * arranged would fail on deploy. So the rows are put in the way the old schema
 * held them, at the version before the change, and the migrations are run over
 * them.
 *
 * **Every run of the migrations is a build of its own.** Doctrine keeps the
 * migrations it has loaded for as long as the build lives and freezes each one
 * once it has run, so running the same one a second time - back down, then up
 * again - in the same build is refused before it reaches the database. A
 * fresh build over the same schema is what a second `bin/trilobit` would be.
 */
#[CoversNothing]
final class MenuEntriesFindTheirMenuThroughTheMigrationsTest extends TestCase
{
    /** The last migration before an entry's menu was a row of its own. */
    private const string BEFORE = Version20260913063908::class;

    private string $schema = '';

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testEveryEntryPointsAtAMenuOfItsOwnBusinessUnderTheNameItHad(): void
    {
        [$ammonite, $belemnite] = $this->arrangedTheOldWay();

        $connection = $this->migrate('latest')->getByType(Connection::class);

        self::assertSame(
            [
                ['About us', 'main', $ammonite],
                ['Imprint', 'footer', $ammonite],
                ['Shop', 'main', $belemnite],
            ],
            $this->rows(
                $connection,
                'SELECT i.label, m.name, m.tenant_id FROM cms_menu_item i JOIN core_menu m ON m.id = i.menu_id ORDER BY i.label',
            ),
            'an entry points at no menu, or at one of another name or of another business',
        );
        $menus = $connection->fetchOne('SELECT COUNT(*) FROM core_menu');
        self::assertIsNumeric($menus);
        self::assertSame(3, (int) $menus, 'a menu was made twice, or one was not made');
    }

    public function testTakingTheMigrationsBackGivesEveryEntryItsNameAgain(): void
    {
        $this->arrangedTheOldWay();

        $this->migrate('latest');
        $connection = $this->migrate(self::BEFORE)->getByType(Connection::class);

        self::assertSame(
            [['About us', 'main'], ['Imprint', 'footer'], ['Shop', 'main']],
            $this->rows($connection, 'SELECT label, menu FROM cms_menu_item ORDER BY label'),
        );
    }

    /**
     * Two businesses, one with two menus, arranged the way the schema before
     * the change held them: a name in a column of the entry.
     *
     * @return array{int, int} the two businesses
     */
    private function arrangedTheOldWay(): array
    {
        $this->schema = Database::schemaFor(self::class);
        $container = $this->build();
        Migrations::run($container);

        $ammonite = (int) Tenants::create($container, 'Ammonite Bikes', 'ammonite.example.com')->id();
        $belemnite = (int) Tenants::create($container, 'Belemnite Books', 'belemnite.example.com')->id();

        $connection = $this->migrate(self::BEFORE)->getByType(Connection::class);
        foreach ([['main', 'About us', $ammonite], ['footer', 'Imprint', $ammonite], ['main', 'Shop', $belemnite]] as [$menu, $label, $tenant]) {
            $connection->insert('cms_menu_item', [
                'menu' => $menu,
                'label' => $label,
                'target_type' => 'url',
                'target' => 'https://www.example.org/',
                'position' => 0,
                'visible' => 1,
                'tenant_id' => $tenant,
            ]);
        }

        return [$ammonite, $belemnite];
    }

    /** Runs the migrations to $version in a build of its own, and hands that build back. */
    private function migrate(string $version): Container
    {
        $container = $this->build();

        $command = new MigrateCommand($container->getByType(DependencyFactory::class));
        new Application()->addCommand($command);

        $tester = new CommandTester($command);
        $status = $tester->execute(
            ['version' => $version, '--allow-no-migration' => true],
            ['interactive' => false, 'capture_stderr_separately' => true],
        );

        self::assertSame(
            Command::SUCCESS,
            $status,
            sprintf('migrating to %s did not run: %s', $version, $tester->getDisplay() . $tester->getErrorOutput()),
        );

        // As in Trilobit\Tests\Migrations: the server commits on every schema
        // statement, so the connection is opened afresh for whatever comes next.
        $container->getByType(Connection::class)->close();

        return $container;
    }

    private function build(): Container
    {
        return Boot::container(ModuleList::of(
            ['cms' => true, 'crm' => false, 'shop' => false],
            Bootstrap::rootDirectory(),
        ));
    }

    /**
     * Every row of $sql, as a list of its values with the numbers as numbers,
     * so that a driver handing integers back as strings changes nothing.
     *
     * @return list<list<int|string>>
     */
    private function rows(Connection $connection, string $sql): array
    {
        $rows = [];
        foreach ($connection->fetchAllNumeric($sql) as $row) {
            $rows[] = array_map(
                static fn(mixed $value): int|string => is_numeric($value)
                    ? (int) $value
                    : (is_string($value) ? $value : get_debug_type($value)),
                $row,
            );
        }

        return $rows;
    }
}
