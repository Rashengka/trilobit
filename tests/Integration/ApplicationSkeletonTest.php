<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration;

use Dom\HTMLDocument;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Http\UrlScript;
use Nette\Routing\Router;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Admin\Menu\Menu;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Event\ListenerCollection;
use Trilobit\Core\Port\PortRegistry;

/**
 * The skeleton, seen through a real compiled container rather than through a
 * constructor call: the container has to compile, the collection points have
 * to arrive empty, and the homepage has to answer with the layout around it.
 */
#[CoversNothing]
final class ApplicationSkeletonTest extends TestCase
{
    private static ?Container $container = null;

    public function testTheContainerCompiles(): void
    {
        self::assertNotSame([], array_keys($this->container()->getServiceDescriptors()));
    }

    public function testTheRouterSendsTheRootToTheHomepage(): void
    {
        $router = $this->container()->getByType(Router::class);
        $match = $router->match(new \Nette\Http\Request(new UrlScript('http://localhost/', '/')));

        self::assertNotNull($match);
        self::assertSame('Core:Front:Home', $match['presenter'] ?? null);
    }

    public function testTheAdminMenuIsEmptyWithoutModules(): void
    {
        self::assertSame([], $this->container()->getByType(Menu::class)->items());
    }

    public function testNoListenerIsRegisteredWithoutModules(): void
    {
        self::assertSame([], $this->container()->getByType(ListenerCollection::class)->all());
    }

    public function testNoPortIsImplementedWithoutModules(): void
    {
        self::assertSame([], $this->container()->getByType(PortRegistry::class)->all());
    }

    public function testTheHomepageRendersInsideTheLayout(): void
    {
        $document = HTMLDocument::createFromString($this->render(), LIBXML_NOERROR);

        self::assertNotNull(
            $document->querySelector('[data-testid="layout"]'),
            'the layout marker is what every later suite asserts the homepage by',
        );
        self::assertNotNull($document->querySelector('[data-testid="layout-header"]'));
        self::assertNotNull($document->querySelector('[data-testid="layout-footer"]'));
        self::assertSame(
            'Trilobit',
            $document->querySelector('[data-testid="homepage-headline"]')?->textContent,
        );
    }

    public function testTheRenderedPageCarriesNoDeveloperOnlyMarkup(): void
    {
        self::assertStringNotContainsString('tracy', strtolower($this->render()));
    }

    private function render(): string
    {
        $factory = $this->container()->getByType(IPresenterFactory::class);
        $presenter = $factory->createPresenter('Core:Front:Home');
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        $response = $presenter->run(new Request('Core:Front:Home', 'GET', ['action' => 'default']));
        self::assertInstanceOf(TextResponse::class, $response);

        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);

        return (string) $source;
    }

    private function container(): Container
    {
        if (!self::$container instanceof Container) {
            self::$container = Bootstrap::boot();

            // Booting the application installs Tracy's global error and
            // exception handlers, which is right for the application and wrong
            // inside a test runner: left in place they would swallow what the
            // next case is trying to observe. The suite hands them back.
            restore_error_handler();
            restore_exception_handler();
        }

        return self::$container;
    }
}
