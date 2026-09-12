<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Config;

use Nette\DI\Container;
use Nette\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Config\Environment;
use Trilobit\Core\Config\Mode;
use Trilobit\Core\Config\ModeNotNamed;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Presentation\Styleguide\StyleguidePages;
use Trilobit\Core\Routing\StyleguideRoutes;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Combination\Build;
use Trilobit\Tests\Template\StyleguideSpecimens;

/**
 * What TRILOBIT_ENV decides once it has been read: the debug mode the build is
 * compiled in, whether it has a style guide, and the mode a command asking
 * whether it may alter data is handed.
 *
 * The builds here leave trilobit.styleguide unstated on purpose, which is the
 * opposite of what every other suite does: the question is what the mode alone
 * makes of it.
 */
#[CoversClass(Bootstrap::class)]
#[CoversClass(Mode::class)]
final class ModeDecidesTheBuildTest extends TestCase
{
    private const string PATH = '/' . StyleguideRoutes::PATH;

    public function testProductionIsBuiltWithoutDebugMode(): void
    {
        self::assertFalse($this->container('prod')->parameters['debugMode']);
    }

    public function testDevelopmentAndStagingAreBuiltInDebugMode(): void
    {
        self::assertTrue($this->container('dev')->parameters['debugMode']);
        self::assertTrue($this->container('staging')->parameters['debugMode']);
    }

    /** Neither its front page nor any of its pages is routed, so each of them is a 404. */
    public function testInProductionThereIsNoStyleGuide(): void
    {
        $container = $this->container('prod');

        self::assertNull(Build::match($container, self::PATH));
        foreach ($container->getByType(StyleguidePages::class)->pages() as $page) {
            self::assertNull(
                Build::match($container, self::PATH . '/' . $page->path()),
                sprintf('production routes the page %s of the style guide', $page->path()),
            );
        }
        self::assertSame([], StyleguideSpecimens::pathsIn($container->getByType(Router::class)));
    }

    public function testWhileDevelopingTheStyleGuideIsThere(): void
    {
        $match = Build::match($this->container('dev'), self::PATH);

        self::assertNotNull($match);
        self::assertSame('Core:Styleguide:Overview', $match['presenter'] ?? null);
    }

    public function testOnStagingTheStyleGuideIsThere(): void
    {
        self::assertNotNull(Build::match($this->container('staging'), self::PATH));
    }

    /** A build a deployment has said otherwise about keeps what it said. */
    public function testAStatedSwitchOverrulesTheMode(): void
    {
        self::assertNull(Build::match($this->container('dev', styleguide: false), self::PATH));
        self::assertNotNull(Build::match($this->container('prod', styleguide: true), self::PATH));
    }

    /**
     * The mode a command is handed is the one the build was made in, so that
     * asking whether data may be altered is asking the container rather than
     * reading the environment a second time.
     */
    public function testTheBuildHandsOutTheModeItWasMadeIn(): void
    {
        self::assertSame(Mode::Dev, $this->container('dev')->getByType(Mode::class));
        self::assertSame(Mode::Staging, $this->container('staging')->getByType(Mode::class));
        self::assertSame(Mode::Prod, $this->container('prod')->getByType(Mode::class));
    }

    public function testTheRetiredSwitchWithoutAModeStopsTheBoot(): void
    {
        $this->expectException(ModeNotNamed::class);
        $this->expectExceptionMessage('TRILOBIT_ENV');

        Boot::container(
            ModuleList::of([], Bootstrap::rootDirectory()),
            styleguide: null,
            environment: Environment::fromValues(['TRILOBIT_DEBUG' => '1']),
        );
    }

    private function container(string $mode, ?bool $styleguide = null): Container
    {
        return Boot::container(
            ModuleList::of(['cms' => true, 'crm' => true, 'shop' => true], Bootstrap::rootDirectory()),
            styleguide: $styleguide,
            environment: Environment::fromValues(['TRILOBIT_ENV' => $mode]),
        );
    }
}
