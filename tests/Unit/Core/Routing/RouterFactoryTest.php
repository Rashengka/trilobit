<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Routing;

use Nette\Application\Routers\RouteList;
use Nette\Http\Request;
use Nette\Http\UrlScript;
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
     * There is deliberately no catch-all route. A path nobody claimed has to
     * come back as null, because that is what tells a later suite that a
     * disabled module really has no routes; a catch-all would answer for it.
     */
    public function testAPathNobodyClaimedIsNotMatched(): void
    {
        $router = new RouterFactory([])->create();

        self::assertNull($router->match($this->request('http://localhost/catalogue')));
        self::assertNull($router->match($this->request('http://localhost/a/b/c/d')));
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
        };
    }
}
