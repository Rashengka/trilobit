<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Generator\Exception\NoChangesDetected;
use Nette\DI\Container;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;

/**
 * The most dangerous thing this project could do, and the one line that stops
 * it.
 *
 * A customer switches a module off. Their database still holds its tables,
 * full of records. A developer runs the migration generator in that build, and
 * the comparison it makes is between a database that has those tables and a
 * mapping that no longer mentions them - because the mapping of a module that
 * is switched off is never loaded. The migration Doctrine writes to reconcile
 * the two drops them, the developer reads a file full of statements about
 * tables they were not thinking about, and the data goes at deploy.
 *
 * The filter over the tables the schema tools may see is what makes that
 * comparison come out empty instead. This test is the claim that it does, and
 * it is deliberately made twice: once with the filter, where nothing at all is
 * generated, and once with it taken off the same connection, where the drop
 * appears. The second half is what stops the first from being a test that
 * would pass with the mechanism removed.
 */
#[CoversNothing]
final class DisabledModuleSchemaTest extends TestCase
{
    /** The module switched off in the build under test. */
    private const string MISSING = 'crm';

    /** Empty until the test has one, because setUp skips before it does. */
    private string $schema = '';

    private string $probeDirectory = '';

    protected function setUp(): void
    {
        $this->schema = Database::schemaFor(self::class);
        $this->probeDirectory = sys_get_temp_dir() . '/trilobit-diff-' . bin2hex(random_bytes(6));
        FileSystem::createDir($this->probeDirectory);

        // The database a customer with every module would have.
        Migrations::run($this->buildWith($this->everyModule()));

        self::assertContains(
            'crm_marker',
            Database::tablesIn($this->schema),
            'the build with every module did not create the table the rest of this test is about',
        );
    }

    /**
     * Runs after a skipped test as well, and a skipped test never got as far
     * as making either of these.
     */
    protected function tearDown(): void
    {
        if ($this->probeDirectory !== '') {
            FileSystem::delete($this->probeDirectory);
        }

        if ($this->schema !== '') {
            Database::drop($this->schema);
        }
    }

    public function testADiffFromABuildMissingAModuleGeneratesNothing(): void
    {
        self::assertNull($this->migrationGeneratedBy($this->buildWithout(self::MISSING)));
    }

    public function testWithoutTheFilterTheSameDiffWouldDropThatModulesTables(): void
    {
        $build = $this->buildWithout(self::MISSING);

        // Exactly the mechanism under test, and nothing else about the build,
        // is taken away: the connection keeps its mapping, its migrations and
        // its data, and stops being told which tables belong to this build.
        $build->getByType(Connection::class)->getConfiguration()->setSchemaAssetsFilter(static fn(): bool => true);

        $generated = $this->migrationGeneratedBy($build);

        self::assertNotNull($generated, 'with no filter the comparison has to notice the tables nobody mapped');
        self::assertStringContainsString('DROP TABLE crm_marker', $generated);
    }

    /**
     * A build missing a module still sees its own tables, so a diff in it is
     * empty because there is nothing to do rather than because the tools are
     * blind. Without this, hiding every table would pass the test above.
     */
    public function testABuildMissingAModuleStillSeesItsOwnTables(): void
    {
        $tables = $this->buildWithout(self::MISSING)
            ->getByType(Connection::class)
            ->createSchemaManager()
            ->listTableNames();

        sort($tables);

        self::assertSame([
            'cms_marker',
            'core_audit_entry',
            'core_media_file',
            'core_migration',
            'core_role',
            'core_setting',
            'core_user',
            'core_user_role',
            'shop_marker',
        ], $tables);
    }

    /**
     * The source of the migration the generator writes for $build, or null
     * when it finds nothing to write.
     *
     * It is generated into a directory of its own so that a failure leaves a
     * file in a temporary place rather than in src/, where the next person to
     * run the migrations would execute it.
     */
    private function migrationGeneratedBy(Container $build): ?string
    {
        $factory = $build->getByType(DependencyFactory::class);
        $factory->getConfiguration()->addMigrationsDirectory(self::class, $this->probeDirectory);

        try {
            $path = $factory->getDiffGenerator()->generate(self::class . '\VersionUnderTest', null);
        } catch (NoChangesDetected) {
            return null;
        }

        return FileSystem::read($path);
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
