<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\HTMLDocument;
use Nette\Routing\Route;
use Nette\Routing\RouteList;
use Nette\Routing\Router;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Routing\StyleguideRoutes;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Combination\Build;

/**
 * What the style guide shows, read off every page it has rather than off one.
 *
 * The gate of decision D5 used to ask a single page, because the guide was a
 * single page. Split into pages, the same question asked of one of them goes
 * wrong in one of two ways: it fails on the first component that moved, or -
 * worse, because nothing turns red - somebody points it at a page that shows
 * nothing and it stops guarding anything at all. So the question here is
 * "does some page of the guide show it", and "the pages of the guide" is not a
 * list written in a test: it is every address the router sends to the style
 * guide, in a build that has one. A page nobody can reach is not a page of the
 * guide, and a page the router does reach cannot be left out.
 *
 * The rule and the reading are kept apart. shownIn() and missing() take the
 * pages as an argument, so the rule can be run over pages carrying exactly the
 * mistake it exists to catch - see
 * Trilobit\Tests\Template\StyleguideShowsEveryComponentTest.
 */
final class StyleguideSpecimens
{
    /** What a section showing a component is marked with. */
    public const string COMPONENT = 'data-styleguide-component';

    /** What a section showing a group of native elements is marked with. */
    public const string CONTENT = 'data-styleguide-content';

    /** What every specimen inside either kind of section is marked with. */
    public const string VARIANT = 'data-styleguide-variant';

    /** @var array<string, HTMLDocument>|null */
    private static ?array $pages = null;

    /**
     * Every page of the style guide, rendered, keyed by the path it answers
     * at.
     *
     * Rendered once per process: the pages do not change between cases, and
     * the whole guide is a few dozen renders nobody needs to pay for twice.
     *
     * @return array<string, HTMLDocument>
     */
    public static function everyPage(): array
    {
        if (self::$pages !== null) {
            return self::$pages;
        }

        $container = Boot::container(
            ModuleList::of(['cms' => true, 'crm' => true, 'shop' => true], Bootstrap::rootDirectory()),
            styleguide: true,
        );

        $pages = [];
        foreach (self::pathsIn($container->getByType(Router::class)) as $path) {
            $pages[$path] = HTMLDocument::createFromString(Build::renderPath($container, $path), LIBXML_NOERROR);
        }

        return self::$pages = $pages;
    }

    /**
     * Every path the style guide answers at in this router.
     *
     * Only fixed paths are accepted. A route with a parameter in it would
     * answer at addresses nobody can list, so a page behind one could be
     * reachable and never looked at here - the one way round this gate that
     * has to be closed rather than remembered.
     *
     * @return list<string>
     */
    public static function pathsIn(Router $router): array
    {
        $paths = [];
        foreach (self::routesIn($router) as $route) {
            $mask = $route->getMask();
            if ($mask !== StyleguideRoutes::PATH && !str_starts_with($mask, StyleguideRoutes::PATH . '/')) {
                continue;
            }

            if (str_contains($mask, '<') || str_contains($mask, '[')) {
                throw new \LogicException(sprintf(
                    'The style guide answers at %s, which is not one address but many; every page of the guide '
                    . 'has to be a route of its own, or this gate cannot know which pages to look at.',
                    $mask,
                ));
            }

            $paths[] = '/' . $mask;
        }

        return $paths;
    }

    /**
     * Everything the given pages show under the given attribute, and where.
     *
     * @param array<string, HTMLDocument> $pages keyed by path
     *
     * @return array<string, list<array{page: string, variants: list<string>}>> keyed by what the
     *     section names; one entry for every section naming it, on whichever page
     */
    public static function shownIn(array $pages, string $attribute): array
    {
        $shown = [];
        foreach ($pages as $path => $page) {
            foreach ($page->querySelectorAll(sprintf('[%s]', $attribute)) as $section) {
                $variants = [];
                foreach ($section->querySelectorAll(sprintf('[%s]', self::VARIANT)) as $specimen) {
                    $variants[] = $specimen->getAttribute(self::VARIANT) ?? '';
                }

                $shown[$section->getAttribute($attribute) ?? ''][] = ['page' => $path, 'variants' => $variants];
            }
        }

        return $shown;
    }

    /**
     * Which of the registered names no page shows.
     *
     * @param list<string> $registered
     * @param array<string, list<array{page: string, variants: list<string>}>> $shown as shownIn() returns it
     *
     * @return list<string>
     */
    public static function missing(array $registered, array $shown): array
    {
        return array_values(array_filter(
            $registered,
            static fn(string $name): bool => ($shown[$name] ?? []) === [],
        ));
    }

    /** @return iterable<Route> */
    private static function routesIn(Router $router): iterable
    {
        if ($router instanceof Route) {
            yield $router;

            return;
        }

        if ($router instanceof RouteList) {
            foreach ($router->getRouters() as $inner) {
                yield from self::routesIn($inner);
            }
        }
    }
}
