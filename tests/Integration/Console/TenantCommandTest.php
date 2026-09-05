<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Console;

use Nette\DI\Container;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Trilobit\Core\Tenancy\HostTenants;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;

/**
 * `bin/trilobit app:tenant`, which is how a fresh installation gets a business
 * for requests to belong to.
 *
 * It comes before the account command, because without a tenant no request is
 * served at all - a host that names no tenant is refused rather than handed to
 * a default one. `localhost` is written down here like any other host, which
 * is the answer to "what about a developer's machine": the same path, not a
 * switch that is one deployment away from being on in production.
 */
#[CoversNothing]
final class TenantCommandTest extends TestCase
{
    private const string SERVICE = 'core.tenantCommand';

    private string $schema = '';

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            Database::drop($this->schema);
        }
    }

    public function testItMakesABusinessThatAnswersAtEveryHostItWasGiven(): void
    {
        $container = $this->emptyDatabase();

        [$status] = $this->execute($container, ['name' => 'Ammonite Bikes', 'hosts' => ['localhost', 'bikes.example.com']]);

        self::assertSame(Command::SUCCESS, $status);

        $hosts = $container->getByType(HostTenants::class);
        $tenant = $hosts->tenantAt('localhost');

        self::assertNotNull($tenant);
        self::assertSame($tenant, $hosts->tenantAt('bikes.example.com'));
    }

    /**
     * Run again it adds what is missing rather than refusing, so that a
     * deployment script may call it every time it deploys.
     */
    public function testRunningItAgainAddsTheHostsThatAreNotThereYet(): void
    {
        $container = $this->emptyDatabase();
        $this->execute($container, ['name' => 'Ammonite Bikes', 'hosts' => ['localhost']]);

        [$status] = $this->execute($container, ['name' => 'Ammonite Bikes', 'hosts' => ['localhost', 'bikes.example.com']]);
        $hosts = $container->getByType(HostTenants::class);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame($hosts->tenantAt('localhost'), $hosts->tenantAt('bikes.example.com'));
    }

    /**
     * A host already answering for somebody else is refused, and this is the
     * one refusal that matters: two businesses at one host is the question
     * "whose request is this" having two answers.
     */
    public function testAHostThatAlreadyAnswersForAnotherBusinessIsRefused(): void
    {
        $container = $this->emptyDatabase();
        $this->execute($container, ['name' => 'Ammonite Bikes', 'hosts' => ['bikes.example.com']]);

        [$status, $output] = $this->execute($container, ['name' => 'Brachiopod Books', 'hosts' => ['bikes.example.com']]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Ammonite Bikes', $output);
    }

    /** The host is written down in one shape, so that a request arriving in another still finds it. */
    public function testAHostIsStoredInTheShapeARequestArrivesIn(): void
    {
        $container = $this->emptyDatabase();
        $this->execute($container, ['name' => 'Ammonite Bikes', 'hosts' => ['Bikes.Example.COM']]);

        self::assertNotNull($container->getByType(HostTenants::class)->tenantAt('bikes.example.com'));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function execute(Container $container, array $arguments): array
    {
        $command = $container->getService(self::SERVICE);
        self::assertInstanceOf(Command::class, $command);

        new Application()->addCommand($command);

        $tester = new CommandTester($command);
        $status = $tester->execute($arguments, ['interactive' => false, 'capture_stderr_separately' => true]);

        return [$status, $tester->getDisplay() . $tester->getErrorOutput()];
    }

    private function emptyDatabase(): Container
    {
        $this->schema = Database::schemaFor(self::class);
        $container = Boot::coreAlone();
        Migrations::run($container);

        return $container;
    }
}
