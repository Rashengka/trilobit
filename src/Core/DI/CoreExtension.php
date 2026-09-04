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
use Trilobit\Core\Event\ListenerCollection;
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
