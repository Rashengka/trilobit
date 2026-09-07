<?php

declare(strict_types=1);

namespace Trilobit\Core\DI;

use Doctrine\Migrations\Version\Comparator;
use Nette\Application\Application;
use Nette\Application\Routers\RouteList;
use Nette\Assets\Registry;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\Definitions\Statement;
use Nette\InvalidStateException;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Nette\Security\User as SignedIn;
use Trilobit\Core\Admin\Menu\Menu;
use Trilobit\Core\Asset\VersionedViteMapper;
use Trilobit\Core\Build\BuildManifest;
use Trilobit\Core\Config\Environment;
use Trilobit\Core\Console\AccountCommand;
use Trilobit\Core\Console\MigrationsDiffCommand;
use Trilobit\Core\Console\TenantCommand;
use Trilobit\Core\Console\WarmupCommand;
use Trilobit\Core\Content\ContentTypes;
use Trilobit\Core\Content\PathRegistry;
use Trilobit\Core\Content\ReservedSegments;
use Trilobit\Core\Contract\Activity\ActivityRecorder;
use Trilobit\Core\Contract\Activity\NullActivityRecorder;
use Trilobit\Core\Contract\Content\ContentLinkResolver;
use Trilobit\Core\Contract\Content\NullContentLinkResolver;
use Trilobit\Core\Contract\Party\NullPartyDirectory;
use Trilobit\Core\Contract\Party\PartyDirectory;
use Trilobit\Core\Doctrine\ChronologicalComparator;
use Trilobit\Core\Doctrine\SchemaAssetsFilter;
use Trilobit\Core\Event\AuditListener;
use Trilobit\Core\Event\Dispatcher;
use Trilobit\Core\Event\ListenerCollection;
use Trilobit\Core\Event\ListenerProvider;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Port\PortRegistry;
use Trilobit\Core\Preference\PreferenceCatalogue;
use Trilobit\Core\Preference\RememberedPreferences;
use Trilobit\Core\Presentation\Component\ComponentRegistry;
use Trilobit\Core\Presentation\Content\ContentGroupRegistry;
use Trilobit\Core\Presentation\Design\DesignSystem;
use Trilobit\Core\Presentation\Front\Signpost\SignpostList;
use Trilobit\Core\Presentation\Front\Signpost\StyleguideSignpost;
use Trilobit\Core\Presentation\Link\Destinations;
use Trilobit\Core\Routing\AdminRoutes;
use Trilobit\Core\Routing\ContentRouter;
use Trilobit\Core\Routing\PreferenceRoutes;
use Trilobit\Core\Routing\RouterFactory;
use Trilobit\Core\Routing\StyleguideRoutes;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Authenticator;
use Trilobit\Core\Security\Permissions;
use Trilobit\Core\Security\PermissionStructure;
use Trilobit\Core\Tenancy\HostTenants;
use Trilobit\Core\Tenancy\Tenancy;
use Trilobit\Core\Tenancy\TenantFromHost;

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

    /** Services tagged with this say which kinds of content they publish; see Trilobit\Core\Content\ContentTypeProvider. */
    public const string TAG_CONTENT_TYPE_PROVIDER = 'trilobit.content_type_provider';

    /** The console's own tag; the value is the name the command answers to. */
    private const string TAG_CONSOLE_COMMAND = 'console.command';

    /**
     * The scope the Vite mapper is registered under in config/common.neon, and
     * therefore the one the templates name in {asset 'vite:...'}.
     */
    private const string ASSET_SCOPE = 'vite';

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
        ContentLinkResolver::class => NullContentLinkResolver::class,
    ];

    /**
     * The migration generator registered by the Nette-to-Doctrine bridge,
     * which Core replaces with its own. Named here so that a bridge release
     * that moves it fails loudly on the next compile rather than quietly
     * leaving the unguarded generator in place.
     */
    private const string BRIDGE_DIFF_COMMAND = 'nettrine.migrations.diffCommand';

    /**
     * The migration runner's own service container, registered by the same
     * bridge, and the one place the order pending migrations run in can be
     * decided. Named here for the same reason the command above is: a release
     * that moves it has to fail loudly on the next compile.
     */
    private const string BRIDGE_DEPENDENCY_FACTORY = 'nettrine.migrations.dependencyFactory';

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

        // Identity. The authenticator is autowired on purpose: Nette\Security\
        // User takes one by type, so registering it here is the whole of the
        // wiring that makes signing in work. A build has exactly one, because
        // there is one place accounts are kept.
        $builder->addDefinition($this->prefix('accounts'))
            ->setFactory(Accounts::class);

        $builder->addDefinition($this->prefix('authenticator'))
            ->setFactory(Authenticator::class);

        // What may be asked about, and the one way of asking it. The structure
        // is read from a file of Core's own rather than configured, because
        // which resources exist is a fact about the code that asks - a
        // deployment cannot add one, since nothing would ever ask about it.
        // Both are in every build: what somebody may do is not a module's
        // question, and the answer has to exist before the first page wants it.
        $builder->addDefinition($this->prefix('permissionStructure'))
            ->setFactory(PermissionStructure::class . '::of', [$this->parameterString('rootDir')]);

        $builder->addDefinition($this->prefix('permissions'))
            ->setFactory(Permissions::class);

        // The first command a new installation runs: without a tenant and a
        // host of its own, every request is refused rather than served by a
        // default one.
        $builder->addDefinition($this->prefix('tenantCommand'))
            ->setFactory(TenantCommand::class)
            ->setAutowired(false)
            ->addTag(self::TAG_CONSOLE_COMMAND, 'app:tenant');

        $builder->addDefinition($this->prefix('accountCommand'))
            ->setFactory(AccountCommand::class)
            ->setAutowired(false)
            ->addTag(self::TAG_CONSOLE_COMMAND, 'app:account');

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

        // Whose request this is. It is in every build and has no default:
        // asking before a tenant has been entered is an error rather than an
        // answer, because the answer would be everybody's rows. See
        // Trilobit\Core\Tenancy\Tenancy.
        $builder->addDefinition($this->prefix('tenancy'))
            ->setFactory(Tenancy::class);

        $builder->addDefinition($this->prefix('hostTenants'))
            ->setFactory(HostTenants::class);

        // Hung on the application's startup in beforeCompile() below, which is
        // where it has to be: the framework runs those before it asks the
        // router anything, and the register the router reads is one address
        // space per tenant.
        $builder->addDefinition($this->prefix('tenantFromHost'))
            ->setFactory(TenantFromHost::class)
            ->setAutowired(false);

        // The public address space: which beginnings content may never take,
        // and the register of what answers where. Both are in every build -
        // the address space is Core's, and a module only ever writes into it.
        $builder->addDefinition($this->prefix('reservedSegments'))
            ->setFactory(ReservedSegments::class . '::of', ['@' . $this->prefix('modules'), []]);

        $builder->addDefinition($this->prefix('pathRegistry'))
            ->setFactory(PathRegistry::class);

        // Which kinds of content this build can draw, and the catch-all that
        // reads the register in front of them. Both are always registered:
        // with no module enabled the collection is empty, the catch-all finds
        // nothing to draw, and every address falls through unrouted - which is
        // the same answer as having no catch-all at all, arrived at without a
        // condition on a module being present.
        $builder->addDefinition($this->prefix('contentTypes'))
            ->setFactory(ContentTypes::class, [[]]);

        $builder->addDefinition($this->prefix('contentRouter'))
            ->setFactory(ContentRouter::class)
            ->setAutowired(false);

        $builder->addDefinition($this->prefix('routerFactory'))
            ->setFactory(RouterFactory::class, [[], '@' . $this->prefix('contentRouter')]);

        $builder->addDefinition($this->prefix('router'))
            ->setType(RouteList::class)
            ->setFactory('@' . $this->prefix('routerFactory') . '::create');

        // The administration is Core's own and is in every build, so its routes
        // are registered unconditionally - unlike the style guide's, which are
        // registered only where that page exists.
        $builder->addDefinition($this->prefix('adminRoutes'))
            ->setFactory(AdminRoutes::class)
            ->setAutowired(false)
            ->addTag(self::TAG_ROUTE_PROVIDER);

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

        // The other half of the same system: the elements a browser hands us
        // before any class is written. They have no files to be counted, so
        // this register is what both the style guide and the stylesheet are
        // checked against.
        $builder->addDefinition($this->prefix('contentGroups'))
            ->setFactory(ContentGroupRegistry::class);

        // Whether this build has the page a stored destination names. It is
        // Core's because the question is about the build rather than about any
        // one module, and it is in every build because a row naming a module
        // outlives that module being switched off; see the class.
        $builder->addDefinition($this->prefix('destinations'))
            ->setFactory(Destinations::class);

        $builder->addDefinition($this->prefix('design'))
            ->setFactory(DesignSystem::class . '::of', [
                $this->parameterString('rootDir'),
                $this->designParameterString('theme'),
            ]);

        // What somebody may decide for themselves about the way the
        // application is drawn, and where that decision is kept (decision D8).
        // Both are in every build: the switch is part of the chrome, not of a
        // module, and the address it writes to has to exist wherever the
        // chrome does.
        $builder->addDefinition($this->prefix('preferences'))
            ->setFactory(PreferenceCatalogue::class . '::of', ['@' . $this->prefix('design')]);

        $builder->addDefinition($this->prefix('rememberedPreferences'))
            ->setFactory(RememberedPreferences::class);

        $builder->addDefinition($this->prefix('preferenceRoutes'))
            ->setFactory(PreferenceRoutes::class)
            ->setAutowired(false)
            ->addTag(self::TAG_ROUTE_PROVIDER);

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

        $this->decorateViteMapper();
        $this->settleTheTenantBeforeRouting();
        $this->letTheProfileWinWhenSomebodySignsIn();
        $this->runTheMigrationsInTheOrderTheyWereWritten();

        // Has to run before taggedPorts() below: a port with no module
        // behind it gets its fallback registered and tagged here, so that
        // from taggedPorts()'s point of view a fallback is indistinguishable
        // from a module that implemented the port itself.
        PortFallback::register($builder, self::PORTS, $this->prefix('port'), self::TAG_PORT);

        $this->service('reservedSegments')->setArguments([
            '@' . $this->prefix('modules'),
            $this->taggedServices(self::TAG_ROUTE_PROVIDER),
        ]);
        $this->service('contentTypes')->setArguments([$this->taggedServices(self::TAG_CONTENT_TYPE_PROVIDER)]);
        $this->service('routerFactory')->setArguments([
            $this->taggedServices(self::TAG_ROUTE_PROVIDER),
            new Reference($this->prefix('contentRouter')),
        ]);
        $this->service('adminMenu')->setArguments([$this->taggedServices(self::TAG_ADMIN_MENU_PROVIDER)]);
        $this->service('signposts')->setArguments([$this->taggedServices(self::TAG_SIGNPOST_PROVIDER)]);
        $this->service('listeners')->setArguments([$this->taggedServices(self::TAG_EVENT_LISTENER)]);
        $this->service('ports')->setArguments([$this->taggedPorts()]);
    }

    /**
     * Hangs decision D8's ordering on the moment somebody signs in: what the
     * person carries takes over from what the device remembered, except where
     * the person carries nothing.
     *
     * It is hung on the framework's own event rather than written into the
     * sign-in page, because a second way of signing in - a link in an e-mail, a
     * single sign-on - would otherwise be a second place to remember this, and
     * forgetting it there would look like nothing at all: the theme would
     * simply be the device's.
     *
     * The absence of the service is refused rather than absorbed, for the same
     * reason as everywhere else in this class: a build that quietly stopped
     * synchronising would look perfectly healthy.
     */
    private function letTheProfileWinWhenSomebodySignsIn(): void
    {
        $builder = $this->getContainerBuilder();
        $name = $builder->getByType(SignedIn::class);
        if ($name === null) {
            throw new InvalidStateException(sprintf(
                'No %s is in the container, so a profile could not be made to win over the device it signs in from. '
                . 'It is registered by nette/security.',
                SignedIn::class,
            ));
        }

        $definition = $builder->getDefinition($name);
        if (!$definition instanceof ServiceDefinition) {
            throw new InvalidStateException(sprintf(
                "Service '%s' was replaced by a %s, so nothing can be hung on it signing somebody in.",
                $name,
                $definition::class,
            ));
        }

        $definition->addSetup('$onLoggedIn[]', [
            [new Reference($this->prefix('rememberedPreferences')), 'whenSomebodySignsIn'],
        ]);
    }

    /**
     * Replaces the order Doctrine would run pending migrations in.
     *
     * Doctrine compares migration names, and a name here is a whole class name
     * with the module's namespace in it, so the alphabet decides which module
     * goes first - see Trilobit\Core\Doctrine\ChronologicalComparator for what
     * that costs a fresh installation. It is done by handing the dependency
     * factory a service rather than by configuration, because the factory is
     * where that choice lives and the bridge exposes no key for it.
     *
     * The absence of the factory is refused rather than absorbed: a build that
     * quietly kept the alphabetical order would look perfectly healthy until
     * somebody installed it from empty.
     */
    private function runTheMigrationsInTheOrderTheyWereWritten(): void
    {
        $builder = $this->getContainerBuilder();
        if (!$builder->hasDefinition(self::BRIDGE_DEPENDENCY_FACTORY)) {
            throw new InvalidStateException(sprintf(
                "'%s' is not in the container, so the migrations could not be put in the order they were written. "
                . 'It is registered by the Nette-to-Doctrine bridge; a release that renames it needs this name changed with it.',
                self::BRIDGE_DEPENDENCY_FACTORY,
            ));
        }

        $definition = $builder->getDefinition(self::BRIDGE_DEPENDENCY_FACTORY);
        if (!$definition instanceof ServiceDefinition) {
            throw new InvalidStateException(sprintf(
                "Service '%s' was replaced by a %s, so nothing can be set on it.",
                self::BRIDGE_DEPENDENCY_FACTORY,
                $definition::class,
            ));
        }

        $definition->addSetup('setService', [Comparator::class, new Statement(ChronologicalComparator::class)]);
    }

    /**
     * Puts the tenant lookup in front of everything the application does with
     * a request.
     *
     * Nette\Application\Application runs onStartup before it asks the router
     * what the path means, so this is what makes "the tenant is known first" a
     * fact of the framework's own order rather than a habit of whoever writes
     * the next presenter.
     *
     * The absence of an application is refused rather than absorbed: a build
     * that served requests without settling the tenant would look perfectly
     * healthy and would be answering out of whichever tenant had a row.
     */
    private function settleTheTenantBeforeRouting(): void
    {
        $builder = $this->getContainerBuilder();
        $name = $builder->getByType(Application::class);
        if ($name === null) {
            throw new InvalidStateException(sprintf(
                'No %s is in the container, so the tenant could not be settled in front of routing. '
                . 'It is registered by nette/application.',
                Application::class,
            ));
        }

        $definition = $builder->getDefinition($name);
        if (!$definition instanceof ServiceDefinition) {
            throw new InvalidStateException(sprintf(
                "Service '%s' was replaced by a %s, so nothing can be hung on its startup.",
                $name,
                $definition::class,
            ));
        }

        $definition->addSetup('$onStartup[]', [new Reference($this->prefix('tenantFromHost'))]);
    }

    /**
     * Wraps the asset mapper Nette registered for the "vite" scope in the one
     * that puts a version on the URL; see Trilobit\Core\Asset\
     * VersionedViteMapper for why the built files are named without a hash and
     * what has to make up for it.
     *
     * It is done by rewriting the addMapper() call rather than by registering
     * a mapper of our own in config/common.neon, because that call is where
     * the base URL of the application is resolved - a dynamic parameter, only
     * known once there is a request - along with the manifest path and the
     * address of a running dev server. A mapper written out by hand would have
     * to restate all three, and would go quietly wrong on an installation
     * served from a subdirectory.
     *
     * Both surprises are refused rather than absorbed: no such call, or more
     * than one, means the bridge no longer looks the way this assumes, and an
     * application that then serves unversioned assets would look perfectly
     * healthy.
     */
    private function decorateViteMapper(): void
    {
        $builder = $this->getContainerBuilder();
        $name = $builder->getByType(Registry::class);
        if ($name === null) {
            throw new InvalidStateException(sprintf(
                'No %s is in the container, so the asset mapper could not be given its versioning. '
                . 'It is registered by nette/assets from the "assets" section of config/common.neon.',
                Registry::class,
            ));
        }

        $definition = $builder->getDefinition($name);
        if (!$definition instanceof ServiceDefinition) {
            throw new InvalidStateException(sprintf(
                "Service '%s' was replaced by a %s, so the mapper it is given can no longer be decorated.",
                $name,
                $definition::class,
            ));
        }

        $decorated = 0;
        foreach ($definition->getSetup() as $setup) {
            // A setup call is stored as [the service it is called on, the
            // method], which is why the entity is an array and not the method
            // name on its own.
            $entity = $setup->getEntity();
            if (!is_array($entity) || $entity[1] !== 'addMapper' || ($setup->arguments[0] ?? null) !== self::ASSET_SCOPE) {
                continue;
            }

            $setup->arguments[1] = new Statement(VersionedViteMapper::class, [$setup->arguments[1]]);
            $decorated++;
        }

        if ($decorated !== 1) {
            throw new InvalidStateException(sprintf(
                "Expected exactly one addMapper('%s', ...) call on %s and found %d. "
                . 'Asset versioning is wired by rewriting that call, so a release that changes its shape needs this changed with it.',
                self::ASSET_SCOPE,
                $name,
                $decorated,
            ));
        }
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
