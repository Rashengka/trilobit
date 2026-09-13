<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Setup;

use DateTimeImmutable;
use Nette\Security\Passwords;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;
use Trilobit\Tests\WebServer;

/**
 * The setup wizard through the real www/index.php, for what only a whole
 * request can show.
 *
 * Two of those are about what a public page on a fresh installation may say.
 * Where the database cannot be reached, a working copy is told what was tried
 * and how to change it - the host, the port, the name, the user, the driver's
 * own words - because that is the whole of what somebody setting it up needs.
 * Production is told that the database is not reachable and nothing else: a
 * host and a user name are a map for whoever is probing the site, and the
 * page is reachable by anybody. The line is debug mode, which is where the
 * framework already shows the same things on its error page.
 *
 * The other two are about the host. A fresh installation has no business, so
 * every request is refused before it is routed - except this one, which has to
 * answer at a host nobody has claimed yet. And once the installation has an
 * administrator the wizard answers exactly as an address nobody claims does.
 */
#[CoversNothing]
final class SetupWizardOverHttpTest extends TestCase
{
    private const string PATH = '/_setup';

    /**
     * Values nothing else in a page would contain, so that finding one means
     * the page gave it away. The host is a loopback address nothing listens on
     * and not the one the page is served from, which the page's own absolute
     * addresses carry.
     */
    private const string NOWHERE_HOST = '127.0.0.2';

    private const string NOWHERE_NAME = 'trilobit_nowhere';

    private const string NOWHERE_USER = 'nobody_setting_up';

    private string $schema = '';

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testInProductionAnUnreachableDatabaseIsSaidWithoutSayingWhere(): void
    {
        $secret = Random::generate(24);
        $port = $this->aPortNothingListensOn();

        $response = WebServer::request($this->unreachable('prod', $port, $secret), self::PATH);

        self::assertSame(503, $response['status'], $response['body']);
        self::assertStringContainsString('data-testid="setup-unreachable"', $response['body']);

        foreach ([self::NOWHERE_HOST, (string) $port, self::NOWHERE_NAME, self::NOWHERE_USER, $secret, 'TRILOBIT_DB', 'SQLSTATE', '.php'] as $disclosure) {
            self::assertStringNotContainsString($disclosure, $response['body'], 'production was told ' . $disclosure);
        }
    }

    public function testOnAWorkingCopyItSaysWhatWasTriedAndWhereToChangeIt(): void
    {
        $secret = Random::generate(24);
        $port = $this->aPortNothingListensOn();

        $response = WebServer::request($this->unreachable('dev', $port, $secret), self::PATH);

        self::assertSame(503, $response['status'], $response['body']);

        foreach (['TRILOBIT_DB_HOST', 'TRILOBIT_DB_PORT', 'TRILOBIT_DB_NAME', 'TRILOBIT_DB_USER', self::NOWHERE_HOST, (string) $port, self::NOWHERE_NAME, self::NOWHERE_USER] as $guidance) {
            self::assertStringContainsString($guidance, $response['body'], 'a working copy was not told ' . $guidance);
        }

        self::assertStringNotContainsString($secret, $response['body'], 'the password is never shown, in any mode');
    }

    /**
     * No business answers at 127.0.0.1 here - there is no business at all -
     * and the wizard answers anyway, in production.
     */
    public function testAFreshDatabaseIsServedTheWizardAtAHostNoBusinessClaims(): void
    {
        $this->schema = Database::schemaFor(self::class);

        $response = WebServer::request(['TRILOBIT_ENV' => 'prod'], self::PATH);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('data-testid="setup-install"', $response['body']);
    }

    /**
     * Decision O3, over the wire: status and page both the ones an address
     * nobody claims gets, so that the answer cannot be told from "there was
     * never anything here".
     */
    public function testOnceInstalledItAnswersAsAnAddressNobodyClaims(): void
    {
        $this->schema = Database::schemaFor(self::class);
        $container = Boot::coreAlone();
        Migrations::run($container);
        Tenants::create($container, 'Ammonite Bikes', '127.0.0.1');
        $container->getByType(Accounts::class)->save(new User(
            'landlord@example.com',
            $container->getByType(Passwords::class)->hash(Random::generate(24)),
            'Lars Landlord',
            new DateTimeImmutable('2026-09-13T08:00:00+00:00'),
            landlord: true,
        ));

        $wizard = WebServer::request(['TRILOBIT_ENV' => 'prod'], self::PATH);
        $nothing = WebServer::request(['TRILOBIT_ENV' => 'prod'], '/_nothing-answers-here');

        self::assertSame(404, $nothing['status'], 'the address meant to answer as nothing does not: ' . $nothing['body']);
        self::assertSame($nothing['status'], $wizard['status']);
        self::assertSame($nothing['body'], $wizard['body']);
    }

    /** @return array<string, string> */
    private function unreachable(string $mode, int $port, string $secret): array
    {
        return [
            'TRILOBIT_ENV' => $mode,
            'TRILOBIT_DB_HOST' => self::NOWHERE_HOST,
            'TRILOBIT_DB_PORT' => (string) $port,
            'TRILOBIT_DB_NAME' => self::NOWHERE_NAME,
            'TRILOBIT_DB_USER' => self::NOWHERE_USER,
            'TRILOBIT_DB_PASSWORD' => $secret,
        ];
    }

    /** A port that was free a moment ago and is closed again, so that a connection to it is refused at once. */
    private function aPortNothingListensOn(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $name = stream_socket_get_name($server, false);
        fclose($server);
        self::assertIsString($name);

        $port = parse_url('tcp://' . $name, PHP_URL_PORT);
        self::assertIsInt($port);

        return $port;
    }
}
