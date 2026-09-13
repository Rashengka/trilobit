<?php

declare(strict_types=1);

/*
 * Makes an empty database for tests/e2e/setup.spec.ts, or drops one it made.
 *
 *   php tests/e2e/fresh-database.php make <copy>     prints the name of the database it made
 *   php tests/e2e/fresh-database.php drop <name>
 *
 * The setup wizard is only there on an installation nobody administers yet,
 * and the database the rest of the browser suite runs over is not one: its
 * specs make an administrator of the installation, and a working copy is
 * seeded with one. So that spec runs a server of its own over a database of
 * its own, made here the way the PHP suites make theirs - Trilobit\Tests\
 * Database, a schema named after the application's with a suffix, which is
 * exactly what the grant in docker/mariadb/init lets the application's user
 * create - and dropped again once the spec is done. Nothing else on the
 * server is touched.
 *
 * The schema is named as one of Trilobit\Tests\Database's own, told apart by
 * the copy of the spec that asked for it, because a schema name has to come
 * from a class there and this script has none; the prefix it gets is what
 * matters, and it is the same.
 */

use Trilobit\Tests\Database;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$trilobitE2eWhat = $argv[1] ?? '';
$trilobitE2eWhich = $argv[2] ?? '';

if ($trilobitE2eWhat === 'make' && preg_match('/^[a-z0-9]+$/', $trilobitE2eWhich) === 1) {
    echo Database::schemaFor(Database::class, 'e2e_setup_' . $trilobitE2eWhich), "\n";

    exit(0);
}

if ($trilobitE2eWhat === 'drop' && $trilobitE2eWhich !== '') {
    Database::drop($trilobitE2eWhich);

    exit(0);
}

fwrite(STDERR, "usage: php tests/e2e/fresh-database.php make <copy> | drop <name>\n");

exit(2);
