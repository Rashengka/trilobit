<?php

declare(strict_types=1);

namespace Trilobit\Core\Doctrine;

/**
 * The one rule that ties a table to the module it belongs to: the module's
 * name, an underscore, and then whatever the table is called.
 *
 * It reads like a naming convention and it is load-bearing. A build without a
 * module has to leave that module's tables in the customer's database alone,
 * and the only thing that can tell the schema tools which tables those are is
 * the name - the mapping of a module that is switched off is not there to ask.
 * So SchemaAssetsFilter goes by this rule, and
 * tests/Architecture/TablePrefixTest is what keeps the names and the
 * namespaces saying the same thing.
 *
 * A module name is one lower-case word with no underscore in it, which is what
 * makes the first underscore an unambiguous boundary.
 */
final class TableName
{
    public static function prefixOf(string $module): string
    {
        return $module . '_';
    }

    /** Which module owns $table, or null when its name carries no module at all. */
    public static function moduleOf(string $table): ?string
    {
        $boundary = strpos($table, '_');
        if ($boundary === false || $boundary === 0) {
            return null;
        }

        return substr($table, 0, $boundary);
    }
}
