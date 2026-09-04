<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Doctrine;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;

/**
 * Switching a module on is a configuration change, not a data migration.
 *
 * An installation that has been running without a module for a year has a
 * database full of everything else, and the day somebody wants the module the
 * answer has to be two lines in a configuration file and one command. What
 * makes that possible is that the record of which migrations have run is
 * shared and holds full class names: the module's own versions are the only
 * ones outstanding, so they are the only ones that run, and nothing else in
 * the database is touched.
 *
 * The claim is made over a database that has been used, not an empty one, so
 * that "nothing else is touched" is something rows can be counted to check.
 */
#[CoversNothing]
final class SwitchingAModuleBackOnTest extends TestCase
{
    /** The module the installation starts without. */
    private const string ABSENT = 'crm';

    private string $schema = '';

    protected function setUp(): void
    {
        $this->schema = Database::schemaFor(self::class);
    }

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            Database::drop($this->schema);
        }
    }

    public function testAModuleSwitchedOnLaterBringsItsTablesAndLeavesTheRestAlone(): void
    {
        $without = $this->buildWithout(self::ABSENT);
        Migrations::run($without);

        self::assertSame(
            [],
            $this->tablesOf(self::ABSENT),
            'a build without the module created its tables anyway',
        );

        // A year of use, as far as this test is concerned.
        $this->addAUser($without, 'someone@example.com');
        $this->addAUser($without, 'somebody.else@example.com');

        $with = $this->buildWith($this->everyModule());
        $output = Migrations::run($with);

        self::assertSame(
            ['crm_marker'],
            $this->tablesOf(self::ABSENT),
            'switching the module on did not bring its tables',
        );

        self::assertSame(
            ['somebody.else@example.com', 'someone@example.com'],
            $this->emails($with),
            'the rows another module had before are not the rows it has now',
        );

        // Only the module's own version was outstanding, so it is the only one
        // that ran. Re-running another module's migration would not have shown
        // up above - its table would have been dropped and made again, taking
        // the rows with it - but it is worth saying out loud all the same.
        self::assertStringContainsString('1 migrations executed', $output);
    }

    /** @return list<string> the tables of $module that are really there */
    private function tablesOf(string $module): array
    {
        return array_values(array_filter(
            Database::tablesIn($this->schema),
            static fn(string $table): bool => str_starts_with($table, $module . '_'),
        ));
    }

    private function addAUser(Container $build, string $email): void
    {
        $manager = $build->getByType(EntityManagerInterface::class);
        $manager->persist(new User($email, 'not a real hash', 'Someone', new DateTimeImmutable()));
        $manager->flush();
        $manager->clear();
    }

    /** @return list<string> sorted */
    private function emails(Container $build): array
    {
        /** @var list<string> $emails */
        $emails = $build->getByType(EntityManagerInterface::class)
            ->createQuery('SELECT u.email FROM ' . User::class . ' u')
            ->getSingleColumnResult();

        sort($emails);

        return $emails;
    }

    /** @param array<string, bool> $modules */
    private function buildWith(array $modules): Container
    {
        return Boot::container(ModuleList::of($modules, Bootstrap::rootDirectory()));
    }

    private function buildWithout(string $module): Container
    {
        $modules = $this->everyModule();
        $modules[$module] = false;

        return $this->buildWith($modules);
    }

    /** @return array<string, bool> */
    private function everyModule(): array
    {
        $root = Bootstrap::rootDirectory();

        return array_fill_keys(ModuleList::fromNeon($root . '/config/modules.neon', $root)->names(), true);
    }
}
