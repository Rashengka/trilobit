<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Tenancy;

use Nette\Application\Application;
use Nette\DI\Container;
use Nette\Http\IRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Tenancy\Tenancy;
use Trilobit\Core\Tenancy\TenancyRefused;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Double\StandInHttpRequest;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * Whose request this is, settled from the host, before the path is routed.
 *
 * The ordering is the claim, not a detail of how it is wired. The register of
 * public addresses is one address space per tenant, so a path resolved before
 * the tenant is known is a path resolved in nobody's address space. The case
 * that states it takes a request the application would otherwise serve - the
 * homepage, a static route - and sends it to a host nobody claims: what comes
 * back has to be the refusal rather than the page, and it could only be the
 * page if the lookup ran late.
 *
 * The other claim is that an unknown host is refused at all. There is no
 * default tenant on purpose. Serving an unknown host out of some tenant hands
 * one business the site of another and looks exactly like a working page while
 * it does so, which is the kind of failure nobody reports.
 */
#[CoversNothing]
final class TenantFromHostTest extends TestCase
{
    private const string BIKES = 'bikes.example.com';

    private const string BIKES_ALIAS = 'bikes.example.org';

    private const string BOOKS = 'books.example.net';

    private string $schema = '';

    /** @var array<string, int> the businesses this installation was given */
    private array $tenants = [];

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            Database::drop($this->schema);
        }
    }

    public function testTheHostSettlesTheTenant(): void
    {
        $container = $this->installation();

        self::assertSame($this->tenantIdOf('Ammonite Bikes'), $this->tenantReachedAt($container, self::BIKES));
        self::assertSame($this->tenantIdOf('Brachiopod Books'), $this->tenantReachedAt($container, self::BOOKS));
    }

    /** Several hosts of one tenant are aliases: another entrance to the same site. */
    public function testASecondHostOfTheSameTenantSettlesTheSameTenant(): void
    {
        $container = $this->installation();

        self::assertSame(
            $this->tenantReachedAt($container, self::BIKES),
            $this->tenantReachedAt($container, self::BIKES_ALIAS),
        );
    }

    public function testAnUnknownHostIsRefusedRatherThanServedByADefault(): void
    {
        $container = $this->installation();

        $this->expectException(TenancyRefused::class);
        $this->expectExceptionMessage("No tenant answers at 'nobody.example.com'");

        $this->arriveAt($container, 'nobody.example.com');
        $this->settleTheTenant($container);
    }

    /**
     * The whole of a request, sent to a host nobody claims but at a path the
     * application does answer at.
     *
     * The homepage is a static route and needs no register and no tenant to
     * resolve, so a build that settled the tenant after routing would draw it.
     * What comes back instead is the refusal, which is the ordering stated as
     * behaviour rather than as wiring.
     */
    public function testAnUnknownHostIsRefusedBeforeThePathIsRouted(): void
    {
        $container = $this->installation();
        $this->arriveAt($container, 'nobody.example.com', '/');

        $application = $container->getByType(Application::class);

        $this->expectException(TenancyRefused::class);

        $application->run();
    }

    /** A tenant is entered by the time anything is routed, not merely available to be. */
    public function testNothingIsRoutedBeforeTheTenantIsEntered(): void
    {
        $container = $this->installation();
        $tenancy = $container->getByType(Tenancy::class);

        self::assertFalse($tenancy->isEntered());

        $this->arriveAt($container, self::BIKES);
        $this->settleTheTenant($container);

        self::assertTrue($tenancy->isEntered());
    }

    private function tenantReachedAt(Container $container, string $host): int
    {
        $this->arriveAt($container, $host);
        $this->settleTheTenant($container);

        return $container->getByType(Tenancy::class)->current();
    }

    /**
     * Runs what the framework runs before it routes anything, and nothing
     * else.
     *
     * Reaching for the application's own list rather than for the service is
     * the point: a lookup that worked but had not been hung there would pass a
     * test that called it directly, and would leave every real request
     * unsettled.
     */
    private function settleTheTenant(Container $container): void
    {
        $application = $container->getByType(Application::class);

        self::assertNotSame([], $application->onStartup, 'nothing runs before the application routes a request');

        foreach ($application->onStartup as $callback) {
            $callback($application);
        }
    }

    private function arriveAt(Container $container, string $host, string $path = '/'): void
    {
        $request = $container->getByType(IRequest::class);

        self::assertInstanceOf(StandInHttpRequest::class, $request);

        $request->arriveAt('http://' . $host . $path);
    }

    private function tenantIdOf(string $name): int
    {
        $id = $this->tenants[$name] ?? null;

        self::assertIsInt($id, $name . ' was not created by this installation');

        return $id;
    }

    /** Two businesses, three hosts, and nothing entered yet. */
    private function installation(): Container
    {
        $this->schema = Database::schemaFor(self::class);
        $container = Boot::container(config: [
            'services' => ['http.request' => ['factory' => StandInHttpRequest::class]],
        ]);
        Migrations::run($container);

        $businesses = [
            'Ammonite Bikes' => [self::BIKES, self::BIKES_ALIAS],
            'Brachiopod Books' => [self::BOOKS],
        ];

        foreach ($businesses as $name => $hosts) {
            $tenant = Tenants::create($container, $name, ...$hosts);
            $id = $tenant->id();
            self::assertNotNull($id);
            $this->tenants[$name] = $id;
        }

        return $container;
    }
}
