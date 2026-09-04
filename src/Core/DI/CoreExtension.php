<?php

declare(strict_types=1);

namespace Trilobit\Core\DI;

use Nette\Application\Routers\RouteList;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\InvalidStateException;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Trilobit\Core\Admin\Menu\Menu;
use Trilobit\Core\Build\BuildManifest;
use Trilobit\Core\Console\WarmupCommand;
use Trilobit\Core\Event\ListenerCollection;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Port\PortRegistry;
use Trilobit\Core\Routing\RouterFactory;

/**
 * The always-enabled part of the application, and the four places a module
 * hands something to it.
 *
 * All four work the same way and for the same reason: a module registers a
 * service and tags it, and Core reads the tag. Core therefore contains no list
 * of modules and no condition on one being enabled. A module that is switched
 * off registers no service at all, so every one of these collections simply
 * comes back shorter - which is what makes "switched off" measurable in the
 * container rather than only visible in the user interface.
 *
 * With no modules enabled all four collections are empty, and that is the
 * state this class is first delivered in.
 */
final class CoreExtension extends CompilerExtension
{
    /** Services tagged with this add routes; see Trilobit\Core\Routing\RouteProvider. */
    public const string TagRouteProvider = 'trilobit.route_provider';

    /** Services tagged with this add administration menu entries. */
    public const string TagAdminMenuProvider = 'trilobit.admin_menu_provider';

    /** Services tagged with this listen to domain events. */
    public const string TagEventListener = 'trilobit.event_listener';

    /** Services tagged with this implement a Core port; the tag value is the interface. */
    public const string TagPort = 'trilobit.port';

    public function getConfigSchema(): Schema
    {
        return Expect::structure([
            'siteName' => Expect::string('Trilobit'),
        ]);
    }

    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();

        // Which modules the build is made of is settled before the container
        // exists, so it arrives as a parameter. Handing it back out as a
        // service is what lets the parts inside the container answer the same
        // question without reading the configuration file a second time.
        //
        // It is read out of the builder rather than written as %modules%,
        // because a parameter reference in a definition put together in code is
        // not expanded the way one written in a configuration file is. Taking
        // the value here also means a malformed one is a compile error with a
        // sentence attached rather than a type error somewhere downstream.
        $builder->addDefinition($this->prefix('modules'))
            ->setType(ModuleList::class)
            ->setFactory(ModuleList::class . '::of', [
                $this->parameterArray('modules'),
                $this->parameterString('rootDir'),
            ]);

        $builder->addDefinition($this->prefix('buildManifest'))
            ->setFactory(BuildManifest::class);

        $builder->addDefinition($this->prefix('warmupCommand'))
            ->setFactory(WarmupCommand::class);

        $builder->addDefinition($this->prefix('routerFactory'))
            ->setFactory(RouterFactory::class, [[]]);

        $builder->addDefinition($this->prefix('router'))
            ->setType(RouteList::class)
            ->setFactory('@' . $this->prefix('routerFactory') . '::create');

        $builder->addDefinition($this->prefix('adminMenu'))
            ->setFactory(Menu::class, [[]]);

        $builder->addDefinition($this->prefix('listeners'))
            ->setFactory(ListenerCollection::class, [[]]);

        $builder->addDefinition($this->prefix('ports'))
            ->setFactory(PortRegistry::class, [[]]);
    }

    /**
     * Tags can only be read once every extension has registered its services,
     * which is why the collections are filled in here and not above.
     */
    public function beforeCompile(): void
    {
        $this->service('routerFactory')->setArguments([$this->taggedServices(self::TagRouteProvider)]);
        $this->service('adminMenu')->setArguments([$this->taggedServices(self::TagAdminMenuProvider)]);
        $this->service('listeners')->setArguments([$this->taggedServices(self::TagEventListener)]);
        $this->service('ports')->setArguments([$this->taggedPorts()]);
    }

    /** @return array<string, bool> */
    private function parameterArray(string $name): array
    {
        $value = $this->getContainerBuilder()->parameters[$name] ?? null;
        if (!is_array($value)) {
            throw new InvalidStateException(sprintf(
                "Parameter '%s' has to be an array of module names; got %s.",
                $name,
                get_debug_type($value),
            ));
        }

        $modules = [];
        foreach ($value as $module => $enabled) {
            if (!is_string($module) || !is_bool($enabled)) {
                throw new InvalidStateException(sprintf(
                    "Parameter '%s' has to map a module name to true or false.",
                    $name,
                ));
            }

            $modules[$module] = $enabled;
        }

        return $modules;
    }

    private function parameterString(string $name): string
    {
        $value = $this->getContainerBuilder()->parameters[$name] ?? null;
        if (!is_string($value)) {
            throw new InvalidStateException(sprintf(
                "Parameter '%s' has to be a string; got %s. It is set by the boot.",
                $name,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    private function service(string $name): ServiceDefinition
    {
        $definition = $this->getContainerBuilder()->getDefinition($this->prefix($name));
        if (!$definition instanceof ServiceDefinition) {
            throw new InvalidStateException(sprintf(
                "Service '%s' was replaced by a %s, so its collection point can no longer be filled in.",
                $this->prefix($name),
                $definition::class,
            ));
        }

        return $definition;
    }

    /**
     * @return list<Reference>
     */
    private function taggedServices(string $tag): array
    {
        $references = [];
        foreach (array_keys($this->getContainerBuilder()->findByTag($tag)) as $name) {
            $references[] = new Reference($name);
        }

        return $references;
    }

    /**
     * @return array<string, Reference> port interface => the service behind it
     */
    private function taggedPorts(): array
    {
        $ports = [];
        foreach ($this->getContainerBuilder()->findByTag(self::TagPort) as $name => $port) {
            if (!is_string($port) || !interface_exists($port)) {
                throw new InvalidStateException(sprintf(
                    "Service '%s' is tagged %s, so the tag value has to be the port interface it implements; got %s.",
                    $name,
                    self::TagPort,
                    get_debug_type($port),
                ));
            }

            if (isset($ports[$port])) {
                throw new InvalidStateException(sprintf(
                    "Port %s is implemented by more than one enabled module; '%s' is the second.",
                    $port,
                    $name,
                ));
            }

            $ports[$port] = new Reference($name);
        }

        return $ports;
    }
}
