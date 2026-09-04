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
use Trilobit\Core\Config\Environment;
use Trilobit\Core\Console\MigrationsDiffCommand;
use Trilobit\Core\Console\WarmupCommand;
use Trilobit\Core\Contract\Activity\ActivityRecorder;
use Trilobit\Core\Contract\Activity\NullActivityRecorder;
use Trilobit\Core\Contract\Party\NullPartyDirectory;
use Trilobit\Core\Contract\Party\PartyDirectory;
use Trilobit\Core\Doctrine\SchemaAssetsFilter;
use Trilobit\Core\Event\AuditListener;
use Trilobit\Core\Event\Dispatcher;
use Trilobit\Core\Event\ListenerCollection;
use Trilobit\Core\Event\ListenerProvider;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Port\PortRegistry;
use Trilobit\Core\Presentation\Component\ComponentRegistry;
use Trilobit\Core\Presentation\Design\DesignSystem;
use Trilobit\Core\Presentation\Front\Signpost\SignpostList;
use Trilobit\Core\Presentation\Front\Signpost\StyleguideSignpost;
use Trilobit\Core\Routing\RouterFactory;
use Trilobit\Core\Routing\StyleguideRoutes;

/**
 * The always-enabled part of the application, and the five places a module
 * hands something to it.
 *
 * All five work the same way and for the same reason: a module registers a
 * service and tags it, and Core reads the tag. Core therefore contains no list
 * of modules and no condition on one being enabled. A module that is switched
 * off registers no service at all, so every one of these collections simply
 * comes back shorter - which is what makes "switched off" measurable in the
 * container rather than only visible in the user interface.
 *
 * With no modules enabled all five collections are empty, and that is the
 * state this class is first delivered in.
 */
final class CoreExtension extends CompilerExtension
{
    /** Services tagged with this add routes; see Trilobit\Core\Routing\RouteProvider. */
    public const string TAG_ROUTE_PROVIDER = 'trilobit.route_provider';

    /** Services tagged with this add administration menu entries. */
    public const string TAG_ADMIN_MENU_PROVIDER = 'trilobit.admin_menu_provider';

    /** Services tagged with this add a homepage entry point; see Trilobit\Core\Presentation\Front\Signpost\SignpostProvider. */
    public const string TAG_SIGNPOST_PROVIDER = 'trilobit.signpost_provider';

    /** Services tagged with this listen to domain events. */
    public const string TAG_EVENT_LISTENER = 'trilobit.event_listener';

    /** Services tagged with this implement a Core port; the tag value is the interface. */
    public const string TAG_PORT = 'trilobit.port';

    /** The console's own tag; the value is the name the command answers to. */
    private const string TAG_CONSOLE_COMMAND = 'console.command';

    /**
     * Every port Core declares, and what stands in for it when no enabled
     * module implements it. See Trilobit\Core\DI\PortFallback and
     * .ai/plans/01a-komunikace-modulu.md §2.
     *
     * @var array<class-string, class-string>
     */
    private const array PORTS = [
        PartyDirectory::class => NullPartyDirectory::class,
        ActivityRecorder::class => NullActivityRecorder::class,
    ];

    /**
     * The migration generator registered by the Nette-to-Doctrine bridge,
     * which Core replaces with its own. Named here so that a bridge release
     * that moves it fails loudly on the next compile rather than quietly
     * leaving the unguarded generator in place.
     */
    private const string BRIDGE_DIFF_COMMAND = 'nettrine.migrations.diffCommand';

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

        // The environment file, as something the configuration can ask for one
        // setting and its fallback on the same line. It is read here rather
        // than handed down from the boot because a fallback belongs next to
        // the key it stands in for; see Environment::value().
        $builder->addDefinition($this->prefix('environment'))
            ->setFactory(Environment::class . '::load', [$this->parameterString('rootDir') . '/.env']);

        // Which tables the schema tools of this build are allowed to see. It
        // is derived from the module list rather than written down, so that
        // switching a module off hides its tables without anybody maintaining
        // a second list of prefixes.
        $builder->addDefinition($this->prefix('schemaAssetsFilter'))
            ->setFactory(SchemaAssetsFilter::class . '::of', ['@' . $this->prefix('modules')]);

        $builder->addDefinition($this->prefix('buildManifest'))
            ->setFactory(BuildManifest::class);

        $builder->addDefinition($this->prefix('warmupCommand'))
            ->setFactory(WarmupCommand::class);

        // Doctrine's migration generator, with the two things a build made of
        // modules has to establish first; see the class. It replaces the one
        // the bridge registers rather than sitting beside it, because the
        // console picks a command by name and two services answering to the
        // same name would be decided by the order they happen to be defined
        // in - which is to say by nothing.
        $builder->addDefinition($this->prefix('migrationsDiffCommand'))
            ->setType(MigrationsDiffCommand::class)
            ->setFactory(MigrationsDiffCommand::class)
            ->setAutowired(false)
            ->addTag(self::TAG_CONSOLE_COMMAND, 'migrations:diff');

        $builder->addDefinition($this->prefix('routerFactory'))
            ->setFactory(RouterFactory::class, [[]]);

        $builder->addDefinition($this->prefix('router'))
            ->setType(RouteList::class)
            ->setFactory('@' . $this->prefix('routerFactory') . '::create');

        $builder->addDefinition($this->prefix('adminMenu'))
            ->setFactory(Menu::class, [[]]);

        $builder->addDefinition($this->prefix('signposts'))
            ->setFactory(SignpostList::class, [[]]);

        // The design system. The register is a list somebody maintains; which
        // themes exist is read off the filesystem, because a theme is a file
        // and a configured list would be a second thing to keep in step with
        // it. Both are always in the container: they describe how the
        // application is drawn, which is true of every build.
        $builder->addDefinition($this->prefix('components'))
            ->setFactory(ComponentRegistry::class);

        $builder->addDefinition($this->prefix('design'))
            ->setFactory(DesignSystem::class . '::of', [
                $this->parameterString('rootDir'),
                $this->designParameterString('theme'),
            ]);

        // The style guide is a page, so it is switched on the way a module is:
        // by whether its services are registered at all. Nothing downstream
        // asks whether it is on - with these two absent there is no route to
        // it and no link to it, so a request ends as 404 (decision D4).
        if ($this->designParameterBool('styleguide')) {
            $builder->addDefinition($this->prefix('styleguideRoutes'))
                ->setFactory(StyleguideRoutes::class)
                ->setAutowired(false)
                ->addTag(self::TAG_ROUTE_PROVIDER);

            $builder->addDefinition($this->prefix('styleguideSignpost'))
                ->setFactory(StyleguideSignpost::class)
                ->setAutowired(false)
                ->addTag(self::TAG_SIGNPOST_PROVIDER);
        }

        $builder->addDefinition($this->prefix('listeners'))
            ->setFactory(ListenerCollection::class, [[]]);

        $builder->addDefinition($this->prefix('ports'))
            ->setFactory(PortRegistry::class, [[]]);

        // Core's own event mechanism - see the class docblock of Dispatcher
        // for why it exists beside the ports above rather than instead of
        // them. The provider is autowired from '@core.listeners' above, the
        // way every other consumer of that collection is.
        $builder->addDefinition($this->prefix('listenerProvider'))
            ->setFactory(ListenerProvider::class);

        // Not autowired: deptrac already stops a module from naming
        // Dispatcher, but psr/event-dispatcher's EventDispatcherInterface is
        // Vendor, which every module may depend on - autowiring left on would
        // hand the dispatcher to any module asking for that interface by
        // type, unseen by deptrac because it never looks inside the
        // container. Something inside Core that needs it takes it by the
        // explicit reference '@core.dispatcher'.
        $builder->addDefinition($this->prefix('dispatcher'))
            ->setFactory(Dispatcher::class)
            ->setAutowired(false);

        // The one listener Core registers for itself, rather than collecting
        // through the tag: the audit trail is Core's own cross-cutting
        // concern, not something a module contributes.
        $builder->addDefinition($this->prefix('auditListener'))
            ->setFactory(AuditListener::class)
            ->setAutowired(false)
            ->addTag(self::TAG_EVENT_LISTENER);
    }

    /**
     * Tags can only be read once every extension has registered its services,
     * which is why the collections are filled in here and not above.
     */
    public function beforeCompile(): void
    {
        $builder = $this->getContainerBuilder();
        if (!$builder->hasDefinition(self::BRIDGE_DIFF_COMMAND)) {
            throw new InvalidStateException(sprintf(
                "'%s' is not in the container, so the unguarded migration generator could not be taken out of it. "
                . 'It is registered by the Nette-to-Doctrine bridge; a release that renames it needs this name changed with it.',
                self::BRIDGE_DIFF_COMMAND,
            ));
        }

        $builder->removeDefinition(self::BRIDGE_DIFF_COMMAND);

        // Has to run before taggedPorts() below: a port with no module
        // behind it gets its fallback registered and tagged here, so that
        // from taggedPorts()'s point of view a fallback is indistinguishable
        // from a module that implemented the port itself.
        PortFallback::register($builder, self::PORTS, $this->prefix('port'), self::TAG_PORT);

        $this->service('routerFactory')->setArguments([$this->taggedServices(self::TAG_ROUTE_PROVIDER)]);
        $this->service('adminMenu')->setArguments([$this->taggedServices(self::TAG_ADMIN_MENU_PROVIDER)]);
        $this->service('signposts')->setArguments([$this->taggedServices(self::TAG_SIGNPOST_PROVIDER)]);
        $this->service('listeners')->setArguments([$this->taggedServices(self::TAG_EVENT_LISTENER)]);
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

    /**
     * One setting out of the trilobit.* group in config/common.neon.
     *
     * The design system's two settings live under a parameter rather than in
     * this extension's own configuration section, because that is where the
     * one setting of the same kind that came before them lives - which module
     * this build is made of - and because a deployment turns the style guide
     * off from config/local.neon without knowing what an extension is called.
     */
    private function designParameter(string $name): mixed
    {
        $group = $this->getContainerBuilder()->parameters['trilobit'] ?? null;
        if (!is_array($group) || !array_key_exists($name, $group)) {
            throw new InvalidStateException(sprintf(
                "Parameter 'trilobit.%s' is missing; config/common.neon is where it is declared.",
                $name,
            ));
        }

        return $group[$name];
    }

    private function designParameterString(string $name): string
    {
        $value = $this->designParameter($name);
        if (!is_string($value)) {
            throw new InvalidStateException(sprintf(
                "Parameter 'trilobit.%s' has to be a string; got %s.",
                $name,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    private function designParameterBool(string $name): bool
    {
        $value = $this->designParameter($name);
        if (!is_bool($value)) {
            throw new InvalidStateException(sprintf(
                "Parameter 'trilobit.%s' has to be true or false; got %s. NEON reads an unquoted 'no' as a string.",
                $name,
                get_debug_type($value),
            ));
        }

        return $value;
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
        foreach ($this->getContainerBuilder()->findByTag(self::TAG_PORT) as $name => $port) {
            if (!is_string($port) || !interface_exists($port)) {
                throw new InvalidStateException(sprintf(
                    "Service '%s' is tagged %s, so the tag value has to be the port interface it implements; got %s.",
                    $name,
                    self::TAG_PORT,
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
