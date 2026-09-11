<?php

declare(strict_types=1);

namespace Trilobit\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Assert;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Config\Environment;
use Trilobit\Tests\Runner\KeepingUnitTestsAwayFromTheDatabase;

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
 * other's tables. When nothing answers, the test fails with the reason said out
 * loud.
 *
 * **It used to skip, and the change is a decision about what the application
 * is.** A skip was right while the database was something the application could
 * be run without; it is now a part of it, so a run that had no server did not
 * find nothing wrong - it found nothing at all, and the two have to look
 * different. They did not: a suite of skips ends in exit 0 and prints a green
 * summary, which is this project's standing example of a failure nobody sees.
 * The cost is named rather than hidden: `composer test` on a machine with no
 * stack running is red, and that is the point rather than a side effect.
 *
 * **A server that answers "no" is not a server that is not there, and a port
 * that never answers at all is a third thing again.** Those three outcomes are
 * the whole of connectToTheServer(): read what that method says before touching
 * the list or the deadline it keeps. What changed is only the verdict of the
 * two that meant "no server", never how they are told apart, and never the
 * messages - which of the three happened is the useful half and it survives.
 *
 * **A unit test never gets here at all.** It is turned away at the door by
 * Trilobit\Tests\Runner\KeepingUnitTestsAwayFromTheDatabase, which explains why
 * the two kinds of test are allowed different worlds.
 */
final class Database
{
    /** Under docker compose the server is the one in compose.yaml. */
    private const string DEFAULT_HOST = '127.0.0.1';

    private const string DEFAULT_PORT = '3306';

    private const string DEFAULT_NAME = 'trilobit';

    private const string DEFAULT_USER = 'trilobit';

    /**
     * How long a server gets to say hello before this run stops waiting.
     *
     * MariaDB writes its handshake the moment it accepts, so the honest answer
     * is measured in milliseconds - 0.01 s to the container in compose.yaml.
     * Ten seconds is therefore not a guess about how slow a real server can be
     * but three orders of magnitude of room on top of it, chosen against a
     * number the project already lives with: compose.yaml's healthcheck calls
     * the database unwell if it has not answered in five, and this allows
     * double that before it stops waiting.
     *
     * It is a deadline for the greeting alone and never for a query, which is
     * the distinction that keeps it from becoming the disease it treats: a
     * DROP DATABASE or an information_schema read over a big schema may take as
     * long as it likes, because by then a server has already said hello.
     */
    private const int SECONDS_TO_SAY_HELLO = 10;

    /**
     * What was found the first time this process listened at each address: ''
     * once a server has greeted us there, otherwise the reason nobody did.
     *
     * Remembered because the wait is the expensive outcome and the suite asks
     * this question two or three times per test. Paid once, a mistyped port
     * costs a run ten seconds and then a few hundred immediate failures; paid
     * every time, it costs a quarter of an hour of a run nobody stays for -
     * which is its own way of telling somebody nothing.
     *
     * Remembering the negative answer was finding N10, and it was a finding
     * while the answer led to a skip: a server that was a second late at the
     * first probe turned the rest of a green run into skips, and the run still
     * left with exit 0. The verdict is now red either way, so a remembered "no"
     * costs a fast red instead of a slow one and can no longer be mistaken for
     * a run that passed.
     *
     * @var array<string, string> keyed by host and port
     */
    private static array $greetings = [];

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

    /**
     * The driver codes that mean no server was ever reached: nothing is
     * listening on the socket, the host name resolves to nothing, or the
     * attempt timed out on the way. Those are the developer who has not run
     * `docker compose up -d`.
     *
     * **The list decides which sentence a reader gets, and no longer whether
     * the run is red.** Everything not in it - the credentials are wrong
     * (1045), the schema is gone (1049), the server has run out of connections
     * (1040) - is a server that answered and refused, and the driver's own
     * account of it is better than anything written here, so it is raised
     * untouched. A code in the list is the same verdict wearing a message that
     * says how to fix it. Both are failures, which is what makes the list safe
     * to be wrong about: forgetting to add a code costs a worse message and
     * never a quiet run, and it used to cost a quiet run. So a new code still
     * goes in here only when it provably means nobody was home, and nothing
     * depends on remembering to.
     */
    private const array NOBODY_ANSWERED = [
        2002, // the socket could not be opened: refused, unreachable, or the name did not resolve
        2003, // the same, from a client that got as far as naming a host and a port
        2005, // the host name is not one this machine can look up
    ];

    /**
     * A connection that has been asked a question and answered it, so that
     * whatever a caller does next fails for its own reasons rather than for
     * this one.
     *
     * Exit condition: this returns only when a server answered. Every other
     * ending raises - nothing was there to answer, nothing answered in time, or
     * something was there and said no - and the three are told apart by the
     * message rather than by the verdict.
     *
     * @throws NoDatabaseToTestAgainst when no server answered
     */
    private static function connectToTheServer(Environment $environment, ?string $schema = null): Connection
    {
        KeepingUnitTestsAwayFromTheDatabase::refuse('a database of its own');

        $host = $environment->value('TRILOBIT_DB_HOST', self::DEFAULT_HOST);
        $port = (int) $environment->value('TRILOBIT_DB_PORT', self::DEFAULT_PORT);
        $user = $environment->value('TRILOBIT_DB_USER', self::DEFAULT_USER);

        $silence = self::whyNobodySaidHello($host, $port);
        if ($silence !== null) {
            throw new NoDatabaseToTestAgainst($silence);
        }

        $parameters = [
            'driver' => 'pdo_mysql',
            'host' => $host,
            'port' => $port,
            'user' => $user,
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
            if (!$failure instanceof DriverException || !in_array($failure->getCode(), self::NOBODY_ANSWERED, true)) {
                throw $failure;
            }

            throw new NoDatabaseToTestAgainst(sprintf(
                'MariaDB is not reachable at %s:%s as %s, so this test could not run: %s. '
                    . 'Start it with `docker compose up -d` and fill in .env; see README.',
                $host,
                $port,
                $user,
                $failure->getMessage(),
            ), $failure->getCode(), $failure);
        }

        return $connection;
    }

    /**
     * Why waiting for this address is not worth doing, or null when a server
     * there has already said hello.
     *
     * The third outcome, and the one neither the driver nor a connect timeout
     * can produce. A port that is listening but is not MySQL - a mistyped
     * TRILOBIT_DB_PORT is the whole of what it takes - accepts the connection
     * and then says nothing, so the client's connect succeeds and the driver
     * settles down to wait for a handshake that is never coming. Nothing is
     * raised, nothing is skipped and nothing is printed: the run simply stops
     * moving, and in CI it sits there until the job is killed for taking too
     * long. It is the same silence as a skip that meant "too many connections",
     * arriving by a different road.
     *
     * **Giving up counts as nobody having answered.** A socket that opens and
     * then stays quiet is not distinguishable from a server that is not up yet,
     * so the message says which of the two it might be and never blames a
     * server that answered - that is what made it honest when this was a skip
     * and is the whole of what it still has to do.
     *
     * It is a failure now for the same reason the plain "nobody is listening"
     * is. Both of them are "there is no database", and a stack that is still
     * starting is precisely a run that could not test anything: it deserves to
     * be red and run again, not to be green and believed.
     */
    private static function whyNobodySaidHello(string $host, int $port): ?string
    {
        $address = $host . ':' . $port;
        self::$greetings[$address] ??= self::listenForAGreeting($host, $port);

        return self::$greetings[$address] === '' ? null : self::$greetings[$address];
    }

    /**
     * Opens a socket of its own and waits for the first byte a MySQL server
     * sends unprompted.
     *
     * Deliberately not a driver connection: this has to be able to give up, and
     * PDO's own timeout cannot, because it covers opening the socket and the
     * socket opens perfectly well. What arrives is not read further than its
     * first byte and not checked for being MySQL - a port that answers with
     * something else is a server that answered, and the driver's own complaint
     * about the packet is a better report than anything guessed here.
     */
    private static function listenForAGreeting(string $host, int $port): string
    {
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $number,
            $problem,
            self::SECONDS_TO_SAY_HELLO,
        );

        // Nothing to connect to at all. Left to the driver, whose account of it
        // names the user and the driver and is the one the message above quotes.
        if ($socket === false) {
            return '';
        }

        stream_set_timeout($socket, self::SECONDS_TO_SAY_HELLO);
        $said = fread($socket, 1);
        $ranOut = stream_get_meta_data($socket)['timed_out'];
        fclose($socket);

        if ($ranOut || $said === false || $said === '') {
            return sprintf(
                'Something is listening on %s:%d but it did not say a word in %d seconds, so this test '
                    . 'could not run. Either the stack is still starting - `docker compose up -d`, then wait '
                    . 'for the database to report healthy - or TRILOBIT_DB_PORT names a port something other '
                    . 'than MariaDB has; see README.',
                $host,
                $port,
                self::SECONDS_TO_SAY_HELLO,
            );
        }

        return '';
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
