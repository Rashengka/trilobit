<?php

declare(strict_types=1);

namespace Trilobit\Tests\Combination;

use Dom\HTMLDocument;
use Nette\Application\InvalidPresenterException;
use Nette\Application\IPresenterFactory;
use Nette\DI\Container;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Admin\Menu\Menu;
use Trilobit\Core\Admin\Menu\MenuItem;

/**
 * Every build the application can be shipped as, started once each.
 *
 * Core plus any subset of the switchable modules is eight builds, and all
 * eight are customers somebody could have. The claim is narrow on purpose: the
 * container compiles, a switched-off module is absent from it, the homepage
 * answers, and a path belonging to a switched-off module is not claimed by
 * anybody. Whether a module does its job is a question for its own suite.
 *
 * The second of those claims is the one that makes "switched off" mean
 * something. A module that only hid its menu entry would pass a screenshot and
 * fail here, because its services would still be in the container.
 */
#[CoversNothing]
final class AllModuleCombinationsTest extends TestCase
{
    private static float $startedAt = 0.0;

    public static function setUpBeforeClass(): void
    {
        self::$startedAt = microtime(true);
    }

    public static function tearDownAfterClass(): void
    {
        Clock::record(microtime(true) - self::$startedAt);
    }

    /**
     * @param list<string> $enabled
     */
    #[DataProviderExternal(Build::class, 'everyCombination')]
    public function testTheContainerCompiles(array $enabled): void
    {
        self::assertNotSame([], Build::serviceNames(Build::container($enabled)));
    }

    /**
     * A module names its services after itself, so the prefix is what a build
     * can be measured by. Counting services would be brittle; the presence of
     * a namespace is not.
     *
     * @param list<string> $enabled
     */
    #[DataProviderExternal(Build::class, 'everyCombination')]
    public function testOnlyTheEnabledModulesHaveServices(array $enabled): void
    {
        $names = Build::serviceNames(Build::container($enabled));

        foreach (Build::Switchable as $module) {
            $owned = array_values(array_filter(
                $names,
                static fn(string $name): bool => str_starts_with($name, $module . '.'),
            ));

            if (in_array($module, $enabled, true)) {
                self::assertNotSame([], $owned, sprintf('%s is enabled and has no service of its own', $module));
            } else {
                self::assertSame([], $owned, sprintf('%s is switched off and is still in the container', $module));
            }
        }
    }

    /**
     * @param list<string> $enabled
     */
    #[DataProviderExternal(Build::class, 'everyCombination')]
    public function testTheHomepageAnswersInsideTheLayout(array $enabled): void
    {
        $document = HTMLDocument::createFromString(
            Build::render(Build::container($enabled), 'Core:Front:Home'),
            LIBXML_NOERROR,
        );

        self::assertNotNull($document->querySelector('[data-testid="layout"]'));
        self::assertSame('Trilobit', $document->querySelector('[data-testid="homepage-headline"]')?->textContent);
    }

    /**
     * The router has no catch-all route, which is what lets this be an
     * assertion rather than a hope: a path nobody claimed comes back as null.
     *
     * @param list<string> $enabled
     */
    #[DataProviderExternal(Build::class, 'everyCombination')]
    public function testOnlyTheEnabledModulesHaveRoutes(array $enabled): void
    {
        $container = Build::container($enabled);

        foreach (Build::Switchable as $module) {
            $match = Build::match($container, '/' . $module);

            if (in_array($module, $enabled, true)) {
                self::assertNotNull($match, sprintf('%s is enabled and its path is not routed', $module));
                self::assertSame(ucfirst($module) . ':Front:Status', $match['presenter'] ?? null);
            } else {
                self::assertNull($match, sprintf('%s is switched off and its path is still routed', $module));
            }
        }
    }

    /**
     * The presenter mapping is the other half of "switched off", and the half
     * a person meets: a link into a module that is not in this build has to
     * fail loudly rather than render an empty page. A module contributes its
     * own mapping from its own configuration file, so a build without it has
     * no mapping and the framework says the presenter does not exist.
     *
     * @param list<string> $enabled
     */
    #[DataProviderExternal(Build::class, 'everyCombination')]
    public function testOnlyTheEnabledModulesHaveAPageToRender(array $enabled): void
    {
        $container = Build::container($enabled);

        foreach (Build::Switchable as $module) {
            $presenter = ucfirst($module) . ':Front:Status';

            if (!in_array($module, $enabled, true)) {
                self::assertNotNull(
                    $this->refusalOf($container, $presenter),
                    sprintf('%s is switched off and its presenter still resolves', $module),
                );

                continue;
            }

            $document = HTMLDocument::createFromString(
                Build::render($container, $presenter),
                LIBXML_NOERROR,
            );

            self::assertNotNull(
                $document->querySelector('[data-testid="layout"]'),
                sprintf('%s renders outside the shared layout', $module),
            );
            self::assertSame(
                ucfirst($module),
                $document->querySelector('[data-testid="module-status-name"]')?->textContent,
            );
        }
    }

    /**
     * The administration menu is the second place the same mechanism is
     * visible, and the one a person would notice. It is asserted here so that
     * a module contributing to one collection point and not the other is a
     * failure now rather than a surprise when there is an administration to
     * render it in.
     *
     * @param list<string> $enabled
     */
    #[DataProviderExternal(Build::class, 'everyCombination')]
    public function testTheAdminMenuHasOneEntryPerEnabledModule(array $enabled): void
    {
        $menu = Build::container($enabled)->getByType(Menu::class);

        self::assertSame(
            array_map(ucfirst(...), $enabled),
            array_map(static fn(MenuItem $item): string => $item->label, $menu->items()),
        );
    }

    private function refusalOf(Container $container, string $presenter): ?InvalidPresenterException
    {
        try {
            $container->getByType(IPresenterFactory::class)->createPresenter($presenter);
        } catch (InvalidPresenterException $refusal) {
            return $refusal;
        }

        return null;
    }
}
