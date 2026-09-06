<?php

declare(strict_types=1);

namespace Trilobit\Tests\Combination;

use Doctrine\Migrations\DependencyFactory;
use Dom\HTMLDocument;
use Nette\Application\InvalidPresenterException;
use Nette\Application\IPresenterFactory;
use Nette\DI\Container;
use Nette\Security\User as SignedIn;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Admin\Menu\Menu;
use Trilobit\Core\Admin\Menu\MenuItem;
use Trilobit\Core\Doctrine\TableName;
use Trilobit\Core\Security\Identity;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;

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

        foreach (Build::SWITCHABLE as $module) {
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
     * A finding from clicking through the running application: the homepage
     * had no way into the section of any enabled module. Every enabled
     * module has to leave a real link on the homepage, and a module that is
     * switched off has to leave neither an empty entry nor a link that
     * points nowhere - the routed path itself has to be gone, the same way
     * testOnlyTheEnabledModulesHaveRoutes above proves it is.
     *
     * @param list<string> $enabled
     */
    #[DataProviderExternal(Build::class, 'everyCombination')]
    public function testTheHomepageLinksToEachEnabledModule(array $enabled): void
    {
        $container = Build::container($enabled);
        $document = HTMLDocument::createFromString(
            Build::render($container, 'Core:Front:Home'),
            LIBXML_NOERROR,
        );

        foreach (Build::SWITCHABLE as $module) {
            $link = $document->querySelector(sprintf('[data-testid="signpost-%s"]', $module));

            if (!in_array($module, $enabled, true)) {
                self::assertNull($link, sprintf('%s is switched off and still has a homepage link', $module));

                continue;
            }

            self::assertNotNull($link, sprintf('%s is enabled and has no homepage link', $module));
            self::assertSame(ucfirst($module), $link->textContent);

            $href = $link->getAttribute('href');
            self::assertNotNull($href, sprintf('%s homepage link carries no href', $module));
            self::assertNotSame('', $href, sprintf('%s homepage link has an empty href', $module));

            self::assertSame(
                Build::match($container, $href)['presenter'] ?? null,
                ucfirst($module) . ':Front:Status',
                sprintf('%s homepage link does not resolve through the router to the module', $module),
            );
        }
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

        foreach (Build::SWITCHABLE as $module) {
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

        foreach (Build::SWITCHABLE as $module) {
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
     * The schema of a build, made the way a customer's is made: by running the
     * migrations, not by asking the mapping for a schema. A schema built from
     * metadata would be right every time and would never once have shown that
     * the migrations themselves are complete.
     *
     * Two claims in one act, because they are one act: the migrations of this
     * build run to the end and leave nothing outstanding, and what they leave
     * behind is the tables of the enabled modules and no others. The second is
     * what catches a migration put in the wrong module - a build without that
     * module would create its tables all the same, and nothing else would
     * notice until a customer switched it off.
     *
     * Tables are checked by the module their name carries rather than one by
     * one, because the claim is about which modules own tables here; which
     * tables a module owns is that module's own business and changes with it.
     *
     * @param list<string> $enabled
     */
    #[DataProviderExternal(Build::class, 'everyCombination')]
    public function testTheMigrationsRunAndLeaveTheTablesOfTheEnabledModules(array $enabled): void
    {
        $schema = Database::schemaFor(self::class, $enabled === [] ? 'core' : implode('_', $enabled));

        try {
            // Built after the schema exists, not taken from the cache: a
            // container remembers which database it was pointed at when it was
            // made. See Build::freshly().
            $container = Build::freshly($enabled);
            Migrations::run($container);

            self::assertCount(
                0,
                $container->getByType(DependencyFactory::class)->getMigrationStatusCalculator()->getNewMigrations(),
                'a migration was left unexecuted',
            );

            $owners = [];
            foreach (Database::tablesIn($schema) as $table) {
                $owner = TableName::moduleOf($table);
                self::assertNotNull($owner, $table . ' carries no module in its name');
                $owners[$owner] = true;
            }

            self::assertSame(
                ['core', ...$enabled],
                array_values(array_unique(['core', ...array_keys($owners)])),
                'the tables in the database do not belong to exactly the enabled modules',
            );

            foreach ($enabled as $module) {
                self::assertArrayHasKey($module, $owners, $module . ' is enabled and owns no table');
            }
        } finally {
            Database::drop($schema);
        }
    }

    /**
     * The administration menu is the second place the same mechanism is
     * visible, and the one a person would notice. It is asserted here so that
     * a module contributing to one collection point and not the other is a
     * failure now rather than a surprise when there is an administration to
     * render it in.
     *
     * What is counted is which modules contributed, not how many entries each
     * one did. A module with an administration worth the name has several
     * sections, and tying the claim to one entry apiece would make the rule
     * "a switched-off module contributes nothing" impossible to state without
     * rewriting it every time a module grows a page. The module an entry
     * belongs to is read off its destination, which is the only thing Core
     * knows about it.
     *
     * @param list<string> $enabled
     */
    #[DataProviderExternal(Build::class, 'everyCombination')]
    public function testTheAdminMenuHoldsEntriesFromExactlyTheEnabledModules(array $enabled): void
    {
        $items = Build::container($enabled)->getByType(Menu::class)->items();

        self::assertSame($enabled, $this->modulesOf(array_map(
            static fn(MenuItem $item): string => $item->destination,
            $items,
        )));

        foreach ($items as $item) {
            self::assertNotSame('', $item->label, 'a menu entry with nothing to call it');
        }
    }

    /**
     * The same claim as above, made against the page a person actually looks
     * at rather than against the container behind it.
     *
     * This is the measured proof T07 is defined by, and it is made for every
     * build the application can be shipped as: the administration menu holds
     * exactly the entries the enabled modules contributed, each one labelled
     * after its module and each one resolving through the router back into it.
     * A build with no optional module has no menu at all - not an empty one -
     * because a navigation with nothing in it is furniture with no purpose.
     *
     * Core contributes nothing here on purpose, which is what makes the count
     * unambiguous; the way back to the overview is the mark in the banner. The
     * identity is invented rather than read from a database: what is under test
     * is which entries the build has, and needing a database to ask that would
     * make this the slowest claim in the suite instead of one of the cheapest.
     *
     * @param list<string> $enabled
     */
    #[DataProviderExternal(Build::class, 'everyCombination')]
    public function testTheRenderedAdministrationMenuHasOneEntryPerEnabledModule(array $enabled): void
    {
        $container = Build::container($enabled);
        $container->getByType(SignedIn::class)->login(new Identity(1, ['administrator'], []));

        try {
            $document = HTMLDocument::createFromString(
                Build::render($container, 'Core:Admin:Dashboard'),
                LIBXML_NOERROR,
            );

            self::assertNotNull($document->querySelector('[data-testid="admin-layout"]'));

            $menu = $document->querySelector('[data-testid="admin-menu"]');
            if ($enabled === []) {
                self::assertNull($menu, 'a build with no optional module drew a menu anyway');

                return;
            }

            self::assertNotNull($menu, 'the enabled modules contributed entries and no menu was drawn');

            $destinations = [];
            foreach ($menu->querySelectorAll('.c-nav__link') as $link) {
                self::assertNotSame('', $link->textContent ?? '', 'a menu entry with nothing to call it');

                $href = $link->getAttribute('href');
                self::assertNotNull($href);

                $presenter = Build::match($container, $href)['presenter'] ?? null;
                self::assertIsString(
                    $presenter,
                    'a menu entry was drawn as a link the router does not claim: ' . $href,
                );

                $destinations[] = $presenter;
            }

            self::assertSame(
                $enabled,
                $this->modulesOf($destinations),
                'the drawn menu does not hold entries from exactly the enabled modules',
            );
        } finally {
            $container->getByType(SignedIn::class)->logout(clearIdentity: true);
        }
    }

    /**
     * Which modules a set of destinations belongs to, sorted and without
     * repeats, so that the answer can be compared with the list of enabled
     * ones however many entries each module contributed.
     *
     * A destination begins with the module's name - that is the whole of what
     * Core knows about where an entry leads - so lower-casing the first
     * segment is the same step Trilobit\Core\Doctrine\TableName takes on the
     * other side of the application.
     *
     * @param list<string> $destinations
     *
     * @return list<string>
     */
    private function modulesOf(array $destinations): array
    {
        $modules = [];
        foreach ($destinations as $destination) {
            $modules[] = strtolower(explode(':', $destination)[0]);
        }

        $modules = array_values(array_unique($modules));
        sort($modules);

        return $modules;
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
