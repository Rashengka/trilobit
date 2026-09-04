<?php

declare(strict_types=1);

namespace Trilobit\Core\Doctrine;

use Trilobit\Core\Module\ModuleList;

/**
 * Which tables this build is allowed to see.
 *
 * This is the piece that protects a customer's data rather than their commit.
 * Switch a module off and its tables stay in the database, full of records,
 * while the mapping the build compiles no longer mentions them. To a schema
 * comparator that is not "tables belonging to somebody else", it is "tables
 * with nothing in the model to justify them" - and the migration it writes to
 * reconcile the two drops them.
 *
 * The filter is the answer: the comparator is shown only the tables of the
 * modules this build is made of, so the tables of a module that is switched
 * off are neither dropped nor altered nor noticed. Doctrine consults it
 * wherever it introspects a database, which is why the table the migrations
 * record themselves in carries a prefix as well - a table this filter hides is
 * a table Doctrine believes does not exist.
 *
 * Note what it does not do. The filter makes a diff safe; it does not make one
 * complete, because a build missing a module also misses that module's mapping
 * and would generate a migration with a hole in it. That is a different
 * problem with a different answer, in Trilobit\Core\Console.
 */
final readonly class SchemaAssetsFilter
{
    /** In every build, so its tables are always visible. */
    private const string AlwaysEnabled = 'core';

    /** @param list<string> $modules the modules whose tables this build may see */
    private function __construct(private array $modules) {}

    public static function of(ModuleList $modules): self
    {
        return new self([self::AlwaysEnabled, ...$modules->enabledNames()]);
    }

    /**
     * Doctrine hands this a table name and takes false for "there is no such
     * table". A name carrying no prefix at all is refused rather than allowed:
     * the strict answer keeps a table nobody placed from quietly becoming
     * everybody's, and the naming rule is enforced by
     * tests/Architecture/TablePrefixTest rather than discovered here.
     */
    public function __invoke(string $name): bool
    {
        return in_array(TableName::moduleOf($name), $this->modules, true);
    }
}
