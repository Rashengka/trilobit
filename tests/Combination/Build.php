<?php

declare(strict_types=1);

namespace Trilobit\Tests\Combination;

use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Http\UrlScript;
use Nette\Routing\Router;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Tests\Boot;

/**
 * One build of the application: a set of switched-on modules, the container
 * that comes out of it, and the two questions this suite is allowed to ask of
 * it.
 *
 * Deliberately not a test case. What belongs here is the machinery every
 * combination shares; what belongs in a test case is the claim. Business
 * behaviour belongs in neither - a combination test asks whether the
 * application starts at all in this shape, and the moment it starts asking
 * more it stops being quick and stops being run.
 */
final class Build
{
    /** The modules that can be switched off. Core is always on and is not one of them. */
    public const array SWITCHABLE = ['cms', 'crm', 'shop'];

    /** @var array<string, Container> */
    private static array $containers = [];

    /**
     * Every subset of the switchable modules, named after what is on, so that
     * a failing case says which build it was.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function everyCombination(): iterable
    {
        $modules = self::SWITCHABLE;

        for ($mask = 0; $mask < 2 ** count($modules); $mask++) {
            $enabled = [];
            foreach ($modules as $bit => $module) {
                if (($mask & (1 << $bit)) !== 0) {
                    $enabled[] = $module;
                }
            }

            yield ($enabled === [] ? 'core alone' : implode(' + ', $enabled)) => [$enabled];
        }
    }

    /**
     * The build, kept between cases so that eight containers are compiled once
     * rather than once per claim.
     *
     * Safe for every question that is answered out of the container itself.
     * Not safe for one that is answered out of a database: see freshly().
     *
     * @param list<string> $enabled
     */
    public static function container(array $enabled): Container
    {
        $key = implode(',', $enabled);
        if (isset(self::$containers[$key])) {
            return self::$containers[$key];
        }

        return self::$containers[$key] = self::freshly($enabled);
    }

    /**
     * The same build, put together again from nothing.
     *
     * A test that makes itself a schema has to have one of these, because a
     * container remembers which database it was pointed at when it was built:
     * rendering a page constructs Nette\Security\User, which constructs the
     * authenticator, which constructs the entity manager and its connection -
     * and the connection takes its database name from the environment at that
     * moment. A container made before Trilobit\Tests\Database created the
     * schema would run the migrations somewhere else entirely, report success,
     * and leave the new schema empty.
     *
     * @param list<string> $enabled
     */
    public static function freshly(array $enabled): Container
    {
        $root = Bootstrap::rootDirectory();
        $modules = [];
        foreach (self::SWITCHABLE as $module) {
            $modules[$module] = in_array($module, $enabled, true);
        }

        return Boot::container(ModuleList::of($modules, $root));
    }

    /**
     * The names of every service in the build, which is where a switched-off
     * module has to be invisible.
     *
     * @return list<string>
     */
    public static function serviceNames(Container $container): array
    {
        return array_keys($container->getServiceDescriptors());
    }

    /**
     * What the router makes of a path, or null when nobody claimed it. There
     * is no catch-all route, so null is a real answer and not an oversight.
     *
     * @return array<string, mixed>|null
     */
    public static function match(Container $container, string $path): ?array
    {
        $router = $container->getByType(Router::class);

        return $router->match(new \Nette\Http\Request(new UrlScript('http://localhost' . $path, '/')));
    }

    /**
     * Renders a page the way the application would, without an HTTP server in
     * the way. A response that is not text is a failure of the caller's
     * expectations, so it is reported as an exception rather than returned.
     */
    public static function render(Container $container, string $presenterName): string
    {
        $presenter = $container->getByType(IPresenterFactory::class)->createPresenter($presenterName);
        if (!$presenter instanceof Presenter) {
            throw new \LogicException(sprintf('%s is not a %s.', $presenterName, Presenter::class));
        }

        $presenter->autoCanonicalize = false;
        $response = $presenter->run(new Request($presenterName, 'GET', ['action' => 'default']));
        if (!$response instanceof TextResponse) {
            throw new \LogicException(sprintf('%s answered with a %s.', $presenterName, $response::class));
        }

        $source = $response->getSource();
        if (!$source instanceof \Stringable) {
            throw new \LogicException(sprintf('%s answered with a %s body.', $presenterName, get_debug_type($source)));
        }

        return (string) $source;
    }
}
