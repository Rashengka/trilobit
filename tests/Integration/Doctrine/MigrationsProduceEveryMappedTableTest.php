<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Tests\Architecture\Mapping;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;

/**
 * Every table the mapping names is really made by the migrations.
 *
 * The gap this closes is one the suite could not see before, and it cost a red
 * build: an entity was added with a migration beside it, the migration was run
 * on the machine it was written on, and from then on every local run used a
 * database that already had the table. Nothing compared the two, so "the
 * schema is behind the entities" could only be discovered somewhere with a
 * fresh database - which is CI, one push later.
 *
 * So the schema here is made the way a customer's is made, by executing the
 * migrations into an empty database, and then read back off the server rather
 * than out of the application - the application is what is under test, and the
 * filter it applies over table names is exactly what could hide a table from
 * it.
 *
 * The comparison is one-directional on purpose. Every mapped table has to be
 * there; a table with nothing mapped to it does not fail, because the record
 * the migrations keep of themselves is one and it is not an entity.
 */
#[CoversNothing]
final class MigrationsProduceEveryMappedTableTest extends TestCase
{
    private string $schema = '';

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            Database::drop($this->schema);
        }
    }

    public function testTheMigrationsMakeATableForEveryMappedEntity(): void
    {
        $this->schema = Database::schemaFor(self::class);

        $root = Bootstrap::rootDirectory();
        $declared = ModuleList::fromNeon($root . '/config/modules.neon', $root)->names();
        $container = Boot::container(ModuleList::of(array_fill_keys($declared, true), $root));
        Migrations::run($container);

        self::assertSame(
            [],
            $this->missingFrom(Database::tablesIn($this->schema), Mapping::ofTheApplication()),
            'the mapping names a table the migrations do not make; generate the missing migration',
        );
    }

    /**
     * The rule above reports nothing, so it would read the same if it looked
     * in the wrong place. Here it is run over three entities the application
     * deliberately does not contain and whose tables therefore exist nowhere,
     * and it has to name all three.
     */
    public function testTheRuleReportsATableNothingMakes(): void
    {
        self::assertSame(
            ['core_thing', 'crm_thing', 'shop_thing'],
            $this->missingFrom(
                ['core_user'],
                Mapping::inDirectory(dirname(__DIR__, 2) . '/Architecture/Fixtures/CrossModule'),
            ),
        );
    }

    /**
     * The tables $mapping names and $tables does not have, sorted.
     *
     * Join tables are counted as well. A table holding nothing but two foreign
     * keys belongs to no entity, so a rule reading only entity names would
     * miss the one kind of table that is easiest to leave out of a migration.
     *
     * @param list<string> $tables
     * @param list<ClassMetadata<object>> $mapping
     * @return list<string>
     */
    private function missingFrom(array $tables, array $mapping): array
    {
        $missing = [];
        foreach ($this->tablesIn($mapping) as $table) {
            if (!in_array($table, $tables, true)) {
                $missing[] = $table;
            }
        }

        $missing = array_values(array_unique($missing));
        sort($missing);

        return $missing;
    }

    /**
     * @param list<ClassMetadata<object>> $mapping
     * @return list<string>
     */
    private function tablesIn(array $mapping): array
    {
        $tables = [];
        foreach ($mapping as $metadata) {
            $tables[] = $metadata->getTableName();

            foreach ($metadata->getAssociationMappings() as $association) {
                if ($association instanceof ManyToManyOwningSideMapping) {
                    $tables[] = $association->joinTable->name;
                }
            }
        }

        return $tables;
    }
}
