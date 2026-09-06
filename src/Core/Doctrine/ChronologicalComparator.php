<?php

declare(strict_types=1);

namespace Trilobit\Core\Doctrine;

use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Version;

/**
 * The order the migrations of a build made of modules have to run in: the
 * order they were written.
 *
 * Doctrine sorts pending migrations by comparing their names, and a name here
 * is the whole class name, one namespace per module
 * (.ai/plans/01-architektura.md §3.3). So `Trilobit\Blog\Migrations\
 * Version20260906070741` sorts before `Trilobit\Core\Migrations\
 * Version20260905083038` for no better reason than that Blog comes before Core
 * in the alphabet - and a fresh installation then tries to create a module's
 * table with a foreign key into a table of Core's that does not exist yet.
 *
 * It is not a hypothetical: it is what happened the first time a module's
 * table pointed at one of Core's, which is the one direction a foreign key is
 * allowed to cross (§3.5). Every module's tables will point at core_tenant, so
 * every module would meet it.
 *
 * The failure is loud, which is the only good thing about it, and it only ever
 * happens on an installation that has not run the migrations yet - a developer
 * whose database is up to date sees nothing wrong. That is why the claim is
 * made by
 * Trilobit\Tests\Integration\Doctrine\MigrationsProduceEveryMappedTableTest,
 * which builds an empty schema every time it runs.
 *
 * So: the timestamp first, and the name only to settle a tie. The timestamp is
 * what the generator puts there and what a person reads a migration's age off,
 * so ordering by it is ordering by the thing that was meant all along.
 * Migrations generated in the same second keep the order they had, which is
 * alphabetical - four of those exist and none of them refers to another
 * module's tables.
 */
final class ChronologicalComparator implements Comparator
{
    /** What the generator names a migration by: the moment it was written. */
    private const string TIMESTAMP = '#Version(\d+)$#';

    public function compare(Version $a, Version $b): int
    {
        $left = $this->writtenAt($a);
        $right = $this->writtenAt($b);

        // A name with no timestamp in it is not one this project generated, so
        // there is nothing to order it by but the name - the answer Doctrine
        // gives on its own.
        return $left === null || $right === null || $left === $right
            ? strcmp((string) $a, (string) $b)
            : $left <=> $right;
    }

    private function writtenAt(Version $version): ?string
    {
        return preg_match(self::TIMESTAMP, (string) $version, $match) === 1 ? $match[1] : null;
    }
}
