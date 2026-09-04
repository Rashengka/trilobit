<?php

declare(strict_types=1);

namespace Trilobit\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use PHPUnit\Framework\Assert;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Config\Environment;

/**
 * A database for a test to have to itself.
 *
 * There is one tested target and it is the one production runs on. SQLite in
 * a test is a green result production never confirmed: a migration is written
 * in the dialect of the server it was generated against, so a suite that runs
 * somewhere else is a suite testing a different application.
 *
 * A test that needs a database gets a schema of its own, named after itself
 * and dropped again afterwards, so that two of them can never be reading each
 * other's tables. When nothing answers, the test is skipped with the reason
 * said out loud - the point of skipping rather than passing is that a run with
 * no database has to look different from a run with one, or the claim quietly
 * stops being made.
 */
final class Database
{
    /** Under docker compose the server is the one in compose.yaml. */
    private const string DEFAULT_HOST = '127.0.0.1';

    private const string DEFAULT_PORT = '3306';

    private const string DEFAULT_NAME = 'trilobit';

    private const string DEFAULT_USER = 'trilobit';

    /**
     * A schema for $owner, empty, with the application pointed at it.
     *
     * The name is put into the environment before it is returned, because that
     * is where the boot reads it from: a container compiled after this call
     * connects to this schema and nothing else.
     *
     * @param class-string $owner the test class the schema belongs to
     * @param string $variant tells apart several schemas one test class needs
     */
    public static function schemaFor(string $owner, string $variant = ''): string
    {
        $environment = self::environment();
        $schema = self::baseName($environment) . '_' . strtolower(self::shortNameOf($owner));
        if ($variant !== '') {
            $schema .= '_' . $variant;
        }

        $server = self::connectToTheServer($environment);
        $server->executeStatement(sprintf('DROP DATABASE IF EXISTS %s', self::quoted($schema)));
        $server->executeStatement(sprintf('CREATE DATABASE %s', self::quoted($schema)));
        $server->close();

        putenv('TRILOBIT_DB_NAME=' . $schema);

        return $schema;
    }

    /** Dropped whether the test passed or not, so a failing run leaves nothing behind. */
    public static function drop(string $schema): void
    {
        $server = self::connectToTheServer(self::environment());
        $server->executeStatement(sprintf('DROP DATABASE IF EXISTS %s', self::quoted($schema)));
        $server->close();

        putenv('TRILOBIT_DB_NAME');
    }

    /**
     * The tables that are really there, read straight from the server rather
     * than through the application - the application is what is under test,
     * and a filter it applies is exactly what could hide a table from it.
     *
     * @return list<string> sorted
     */
    public static function tablesIn(string $schema): array
    {
        $connection = self::connectToTheServer(self::environment(), $schema);

        /** @var list<string> $tables */
        $tables = $connection->fetchFirstColumn(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name',
            [$schema],
        );
        $connection->close();

        return $tables;
    }

    private static function environment(): Environment
    {
        return Environment::load(Bootstrap::rootDirectory() . '/.env');
    }

    private static function baseName(Environment $environment): string
    {
        $name = $environment->value('TRILOBIT_DB_NAME', self::DEFAULT_NAME);

        // A schema this test made carries a suffix, so a second suffix on top
        // of it would name a schema of a schema. Whatever is in the
        // environment while a test is running is stripped back to the base.
        return explode('_', $name)[0];
    }

    private static function connectToTheServer(Environment $environment, ?string $schema = null): Connection
    {
        $parameters = [
            'driver' => 'pdo_mysql',
            'host' => $environment->value('TRILOBIT_DB_HOST', self::DEFAULT_HOST),
            'port' => (int) $environment->value('TRILOBIT_DB_PORT', self::DEFAULT_PORT),
            'user' => $environment->value('TRILOBIT_DB_USER', self::DEFAULT_USER),
            'password' => $environment->value('TRILOBIT_DB_PASSWORD'),
            'charset' => 'utf8mb4',
        ];

        if ($schema !== null) {
            $parameters['dbname'] = $schema;
        }

        $connection = DriverManager::getConnection($parameters);

        try {
            $connection->executeQuery('SELECT 1');
        } catch (Exception $failure) {
            Assert::markTestSkipped(sprintf(
                'MariaDB is not reachable at %s:%s as %s, so this test could not run: %s. '
                    . 'Start it with `docker compose up -d` and fill in .env; see README.',
                $parameters['host'],
                $parameters['port'],
                $parameters['user'],
                $failure->getMessage(),
            ));
        }

        return $connection;
    }

    /** @param class-string $class */
    private static function shortNameOf(string $class): string
    {
        $segments = explode('\\', $class);

        return end($segments);
    }

    private static function quoted(string $schema): string
    {
        Assert::assertMatchesRegularExpression(
            '#^[a-z0-9_]+$#',
            $schema,
            'a schema name is put into a statement that cannot be parameterised, so it has to be plain',
        );

        return '`' . $schema . '`';
    }
}
