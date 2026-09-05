<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Routing;

use Nette\Application\Routers\RouteList;
use Nette\Http\IRequest;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Routing\RouteProvider;
use Trilobit\Core\Routing\RouterFactory;

#[CoversClass(RouterFactory::class)]
final class RouterFactoryTest extends TestCase
{
    public function testTheRootLeadsToTheCoreHomepage(): void
    {
        $match = new RouterFactory([])->create()->match($this->request('http://localhost/'));

        self::assertNotNull($match);
        self::assertSame('Core:Front:Home', $match['presenter'] ?? null);
        self::assertSame('default', $match['action'] ?? null);
    }

    /**
     * A path nobody claimed comes back as null, which is what tells a later
     * suite that a disabled module really has no routes.
     *
     * The register of public addresses claims what is left over, and it is
     * still null that comes back for a path it does not hold - it answers for
     * an address it really has and for nothing else. Here it is absent
     * altogether, which is the build a suite gets when it assembles the router
     * by hand rather than out of the container.
     */
    public function testAPathNobodyClaimedIsNotMatched(): void
    {
        $router = new RouterFactory([])->create();

        self::assertNull($router->match($this->request('http://localhost/catalogue')));
        self::assertNull($router->match($this->request('http://localhost/a/b/c/d')));
    }

    /**
     * The register of public addresses is the least specific thing in the
     * list, so it is asked last - a static route and a short address both
     * answer before it. Order is the whole of that guarantee, so it is stated
     * here rather than left to the order the arguments happen to be used in.
     */
    public function testTheRegisterOfPublicAddressesIsAskedLast(): void
    {
        $content = new class implements Router {
            /** @return array<string, mixed> */
            public function match(IRequest $httpRequest): array
            {
                return ['presenter' => 'Demo:Front:Content', 'action' => 'default'];
            }

            /** @param array<string, mixed> $params */
            public function constructUrl(array $params, UrlScript $refUrl): ?string
            {
                return null;
            }
        };

        $router = new RouterFactory([$this->provider()], $content)->create();

        self::assertSame(
            'Demo:Front:Catalogue',
            $router->match($this->request('http://localhost/catalogue'))['presenter'] ?? null,
        );
        self::assertSame(
            'Demo:Front:Content',
            $router->match($this->request('http://localhost/anything-else'))['presenter'] ?? null,
        );
    }

    public function testAProviderIsAskedForItsRoutes(): void
    {
        $router = new RouterFactory([$this->provider()])->create();
        $match = $router->match($this->request('http://localhost/catalogue'));

        self::assertNotNull($match);
        self::assertSame('Demo:Front:Catalogue', $match['presenter'] ?? null);
    }

    public function testTheHomepageStaysWithCoreEvenWhenAProviderIsRegistered(): void
    {
        $match = new RouterFactory([$this->provider()])->create()->match($this->request('http://localhost/'));

        self::assertNotNull($match);
        self::assertSame('Core:Front:Home', $match['presenter'] ?? null);
    }

    private function request(string $url): Request
    {
        return new Request(new UrlScript($url, '/'));
    }

    private function provider(): RouteProvider
    {
        return new class implements RouteProvider {
            public function provide(RouteList $routes): void
            {
                $routes->addRoute('', 'Demo:Front:Catalogue:default');
                $routes->addRoute('catalogue', 'Demo:Front:Catalogue:default');
            }

            /** @return list<string> */
            public function reservedSegments(): array
            {
                return ['catalogue'];
            }
        };
    }
}
