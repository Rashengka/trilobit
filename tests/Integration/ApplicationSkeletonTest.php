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
use Psr\EventDispatcher\EventDispatcherInterface;
use Trilobit\Core\Admin\Menu\Menu;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Contract\Activity\ActivityRecorder;
use Trilobit\Core\Contract\Activity\NullActivityRecorder;
use Trilobit\Core\Contract\Party\NullPartyDirectory;
use Trilobit\Core\Contract\Party\PartyDirectory;
use Trilobit\Core\Contract\Party\PartyLookup;
use Trilobit\Core\Event\AuditListener;
use Trilobit\Core\Event\ListenerCollection;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Port\PortRegistry;
use Trilobit\Tests\Boot;

/**
 * The skeleton, seen through a real compiled container rather than through a
 * constructor call: the container has to compile, the collection points have
 * to arrive empty, and the homepage has to answer with the layout around it.
 *
 * The build under test is Core on its own, whatever config/modules.neon says.
 * That is the claim worth making here - Core does not need a module to work -
 * and it is the build no combination of modules can accidentally repair.
 */
#[CoversNothing]
final class ApplicationSkeletonTest extends TestCase
{
    private static ?Container $container = null;

    public function testTheContainerCompiles(): void
    {
        self::assertNotSame([], array_keys($this->container()->getServiceDescriptors()));
    }

    /**
     * The compiled container is cached by its static parameters and, outside
     * debug mode, by nothing else - so what the configuration files say has to
     * be one of them, or a change to a NEON file has no effect and no error
     * either. Trilobit\Tests\Unit\Core\BootstrapTest is what says the value
     * changes when a file does; this is what says the container carries it.
     */
    public function testTheContainerIsCachedByWhatTheConfigurationSays(): void
    {
        $modules = ModuleList::of([], Bootstrap::rootDirectory());

        self::assertSame(
            Bootstrap::configurationHash(Bootstrap::configurationFiles($modules)),
            $this->container()->getParameters()['configHash'] ?? null,
        );
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

    /**
     * Core registers a listener of its own - AuditListener - regardless of
     * which modules are enabled, so "no modules" leaves exactly that one
     * rather than an empty collection.
     */
    public function testOnlyCoresOwnListenerIsRegisteredWithoutModules(): void
    {
        self::assertContainsOnlyInstancesOf(
            AuditListener::class,
            $this->container()->getByType(ListenerCollection::class)->all(),
        );
    }

    /**
     * No module implements either port, so what is behind them is the null
     * fallback Trilobit\Core\DI\CoreExtension registers for each - never an
     * empty registry, because a caller takes the port as a plain constructor
     * dependency and always needs an answer; see
     * .ai/plans/01a-komunikace-modulu.md §2.
     */
    public function testEveryPortFallsBackToItsNullImplementationWithoutModules(): void
    {
        $ports = $this->container()->getByType(PortRegistry::class);

        self::assertInstanceOf(NullPartyDirectory::class, $ports->get(PartyDirectory::class));
        self::assertInstanceOf(NullActivityRecorder::class, $ports->get(ActivityRecorder::class));
    }

    /**
     * The measurable claim T04 is defined by: a caller asking the container
     * for the port directly, the way a module's own constructor would,
     * receives the null implementation and not an exception - in a build
     * with no module able to answer for real, which "without Crm" is a
     * special case of.
     */
    public function testThePortResolvesToItsNullImplementationByType(): void
    {
        $directory = $this->container()->getByType(PartyDirectory::class);

        self::assertInstanceOf(NullPartyDirectory::class, $directory);
        self::assertNull($directory->find(new PartyLookup(email: 'person@example.com')));
    }

    /**
     * The regression this guards: deptrac stops a module from naming
     * Trilobit\Core\Event\Dispatcher, but not from asking the container for
     * Psr\EventDispatcher\EventDispatcherInterface, which is Vendor and every
     * module may depend on it. The dispatcher is registered with autowiring
     * off for exactly that reason - see the comment next to 'dispatcher' in
     * CoreExtension - so asking for it by type, the way a module's own
     * constructor would, has to come back empty. This is the real,
     * fully-compiled container; Trilobit\Tests\Unit\Core\DI\DispatcherFallbackTest
     * proves the same mechanism in isolation.
     */
    public function testTheDispatcherDoesNotResolveByType(): void
    {
        self::assertNull($this->container()->getByType(EventDispatcherInterface::class, false));
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
            self::$container = Boot::coreAlone();
        }

        return self::$container;
    }
}
