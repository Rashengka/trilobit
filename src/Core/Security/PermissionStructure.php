<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

use Nette\Neon\Neon;

/**
 * The pieces every role in this installation is put together out of: which
 * resources exist, what each of them falls under, and which privileges make
 * sense on it.
 *
 * It is one thing for the whole installation and it does not depend on a
 * tenant. A tenant may have roles of its own - that is what a role is, a
 * combination of these pieces - but it may not invent a piece, because a piece
 * exists exactly when some code asks about it. That is what keeps this table
 * constant from one build: it does not grow with the number of businesses, so
 * it is read once and shared by every request, and the access list built on
 * top of it (see Trilobit\Core\Security\Permissions) is the only part that has
 * to be per tenant.
 *
 * **It is put together from Core's resources and the modules'.** Core's are
 * Trilobit\Core\Security\Resource described in src/Core/Security/permissions.neon;
 * each module this build is made of brings an enum of its own and a file of its
 * own through Trilobit\Core\Security\ResourceProvider, because Core may not
 * name a module and one enum in Core could therefore not stay complete. What
 * this build has is resources() - the one list registration walks, so that no
 * list written beside it can part company with it. A module that is switched
 * off brings nothing, and its resources are simply not here.
 *
 * **Each file speaks for the resources brought with it, all of them, and
 * nobody else's.** A resource an enum has and its file does not describe is
 * refused when this is read, and so is a file describing a resource it did not
 * bring: otherwise switching a module on could change what may be asked of one
 * of Core's, and the change would sit in a file nobody reading Core's would
 * open. Two resources under one name are refused too - a piece written under
 * it would read back as whichever came first, and which came first is the
 * order modules happen to be registered in. Every one of these mistakes has its
 * symptom at somebody else's first question, as an exception rather than as a
 * denial, so they are refused where the build starts instead.
 *
 * **What a resource falls under is its name**, read by
 * Trilobit\Core\Security\ResourceTree when this is read - a module's resource
 * included, which is how one falls under Core's administration without Core
 * knowing it is there. A file has no way of saying it: a key for it would be
 * refused like any key nobody reads.
 */
final readonly class PermissionStructure
{
    /** Under the project root. It is Core's own file: the structure is the application's, not a deployment's. */
    public const string FILE = 'src/Core/Security/permissions.neon';

    private const string PRIVILEGES = 'privileges';

    private const string BUNDLE = 'bundle';

    /** Every key a resource may be described by; see describedIn(). */
    private const array KEYS = [self::PRIVILEGES, self::BUNDLE];

    /**
     * @param list<ResourceName> $resources every resource of this build, Core's
     *     first and then each module's, in the order they were brought
     * @param array<string, ResourceName> $byName the same, by the resource's name
     * @param array<string, non-empty-list<Privilege>> $privileges what may be
     *     asked of each resource, by the resource's name
     * @param array<string, bool> $bundles whether the whole of each resource
     *     may be granted, by the resource's name
     */
    private function __construct(
        private ResourceTree $tree,
        private array $resources,
        private array $byName,
        private array $privileges,
        private array $bundles,
    ) {}

    /**
     * The structure of a build: Core's resources and those of every module the
     * build is made of. There is no default for the modules on purpose - a
     * structure read without them would be one that quietly has none of their
     * resources, and every question about one would raise.
     *
     * @param iterable<ResourceProvider> $providers
     */
    public static function of(string $rootDirectory, iterable $providers): self
    {
        $sources = [[Resource::cases(), $rootDirectory . '/' . self::FILE]];
        foreach ($providers as $provider) {
            $sources[] = [$provider->resources(), $provider->structureFile()];
        }

        return self::read($sources);
    }

    /** Core's resources alone, described by $file instead of the shipped one. */
    public static function fromNeon(string $file): self
    {
        return self::read([[Resource::cases(), $file]]);
    }

    /**
     * The name a resource is registered and stored under: the path to it.
     *
     * A resource named by anything else never gets into a structure - see
     * read() - so this is where the one shape a name has is written down.
     */
    public static function nameOf(ResourceName $resource): string
    {
        return (string) $resource->value;
    }

    /**
     * Every resource of this build, Core's first.
     *
     * @return list<ResourceName>
     */
    public function resources(): array
    {
        return $this->resources;
    }

    /**
     * The resource of this build called $name, or null when this build has
     * none - one an earlier build had, or one a module brings that this build
     * is made without.
     */
    public function resourceNamed(string $name): ?ResourceName
    {
        return $this->byName[$name] ?? null;
    }

    /** @return non-empty-list<Privilege> */
    public function privilegesOf(ResourceName $resource): array
    {
        return $this->privileges[$this->ownName($resource)];
    }

    /**
     * Whether asking this of that is a question this build has an answer for.
     * A resource this build does not have offers nothing.
     */
    public function offers(ResourceName $resource, Privilege $privilege): bool
    {
        return $this->has($resource)
            && in_array($privilege, $this->privileges[self::nameOf($resource)], true);
    }

    /**
     * Whether the whole of this resource may be granted - every privilege of
     * it and of everything under it, including the ones added after the role
     * was written.
     *
     * That last part is the reason it has to be said rather than assumed. A
     * whole piece keeps growing, so it is honoured only where somebody decided
     * that growing is what the resource should do; see
     * src/Core/Security/permissions.neon. Taking the whole of a resource away
     * needs no such decision - a denial that grows fails in the safe
     * direction - so this is asked of grants and never of denials.
     */
    public function offersBundle(ResourceName $resource): bool
    {
        return $this->has($resource) && $this->bundles[self::nameOf($resource)];
    }

    /**
     * What everything else falls under: the application inside one business.
     *
     * The whole of it is every section there is and every one added later,
     * which is what owning a business means - so it is the one piece only the
     * owner's role may hold; see Trilobit\Core\Security\AccessComposition.
     * It is found in the tree rather than named, so that asking for it is not
     * a mention of a resource that no question follows - see
     * tests/Architecture/EveryPermissionQuestionIsPredefinedTest.
     *
     * There is one, because every resource's name begins with it. A tree with
     * none or with two is refused rather than answered with one of them: the
     * one picked would be the one the owner's role holds.
     */
    public function root(): ResourceName
    {
        $roots = array_values(array_filter(
            $this->resources,
            fn(ResourceName $resource): bool => $this->tree->ancestorsOf(self::nameOf($resource)) === [],
        ));

        if (count($roots) !== 1) {
            throw new \LogicException(sprintf(
                'Everything has to fall under one resource, and %s is what falls under nothing.',
                $roots === [] ? 'no resource' : implode(', ', array_map(self::nameOf(...), $roots)),
            ));
        }

        return $roots[0];
    }

    /**
     * Every piece a role could be assembled from in this build.
     *
     * It is what a role that may do everything is made of, and it is derived
     * here rather than written down beside whoever wants it: a second list
     * would go on saying what this build used to offer, and the account holding
     * it would quietly stop being able to reach whatever was added after the
     * list was written.
     *
     * @return list<Grant>
     */
    public function everyPair(): array
    {
        $pairs = [];
        foreach ($this->resources as $resource) {
            foreach ($this->privilegesOf($resource) as $privilege) {
                $pairs[] = new Grant($resource, $privilege);
            }
        }

        return $pairs;
    }

    /**
     * Everything that falls under this resource, however deep - whichever
     * module brought it.
     *
     * It is how far the whole of the resource reaches, granted or denied, and
     * nothing narrower: a concrete privilege on the resource says nothing about
     * what is under it. It is asked for here rather than left to Nette because
     * a parent inside an access list is the route a right taken away further
     * down would come back by; see Trilobit\Core\Security\AccessComposition.
     *
     * @return list<ResourceName>
     */
    public function descendantsOf(ResourceName $resource): array
    {
        return $this->named($this->tree->descendantsOf($this->ownName($resource)));
    }

    /**
     * Everything this resource falls under, the nearest first and however
     * high.
     *
     * It is what any right on the resource opens: somebody who may work in a
     * section may get to it, so they may view each thing it is inside.
     *
     * @return list<ResourceName>
     */
    public function ancestorsOf(ResourceName $resource): array
    {
        return $this->named($this->tree->ancestorsOf($this->ownName($resource)));
    }

    /**
     * Whether $resource is one of this build's - the very case, and not merely
     * another enum's case under the same name.
     */
    private function has(ResourceName $resource): bool
    {
        return ($this->byName[self::nameOf($resource)] ?? null) === $resource;
    }

    /**
     * The name of a resource of this build. Anything else is a mistake in the
     * code asking - it walked a resource it was not handed by this structure -
     * and is refused rather than answered with nothing.
     */
    private function ownName(ResourceName $resource): string
    {
        if (!$this->has($resource)) {
            throw new \LogicException(sprintf(
                '%s::%s is not a resource of this build, so nothing about it can be read here.',
                $resource::class,
                $resource->name,
            ));
        }

        return self::nameOf($resource);
    }

    /**
     * @param list<string> $names
     *
     * @return list<ResourceName>
     */
    private function named(array $names): array
    {
        return array_map(fn(string $name): ResourceName => $this->byName[$name], $names);
    }

    /**
     * @param list<array{list<ResourceName>, string}> $sources what each part of
     *     the build brings, with the file describing it; Core's first
     */
    private static function read(array $sources): self
    {
        $resources = [];
        $byName = [];
        foreach ($sources as [$brought]) {
            foreach ($brought as $resource) {
                $name = $resource->value;
                if (!is_string($name)) {
                    throw new \RuntimeException(sprintf(
                        '%s::%s is named by a number, and a resource is named by the path to it: '
                            . 'a number says nothing about what it falls under.',
                        $resource::class,
                        $resource->name,
                    ));
                }

                $standing = $byName[$name] ?? null;
                if ($standing instanceof ResourceName) {
                    throw new \RuntimeException(sprintf(
                        "'%s' is brought by both %s::%s and %s::%s. A piece written under that name would read back "
                            . 'as whichever came first, and which comes first is the order modules happen to be '
                            . 'registered in.',
                        $name,
                        $standing::class,
                        $standing->name,
                        $resource::class,
                        $resource->name,
                    ));
                }

                $byName[$name] = $resource;
                $resources[] = $resource;
            }
        }

        $tree = ResourceTree::of(array_keys($byName));

        $privileges = [];
        $bundles = [];
        foreach ($sources as [$brought, $file]) {
            [$described, $whole] = self::describedIn($file, $brought);
            $privileges += $described;
            $bundles += $whole;
        }

        foreach ($resources as $resource) {
            $name = self::nameOf($resource);
            $parent = $tree->parentOf($name);
            if ($parent !== null && !in_array(Privilege::View, $privileges[$parent], true)) {
                throw new \RuntimeException(sprintf(
                    "'%s' falls under '%s', which does not offer view. Any right on '%s' opens what it falls under, "
                        . "and opening is view - so every piece of '%s' would be a way into a resource that could "
                        . 'not be opened.',
                    $name,
                    $parent,
                    $name,
                    $name,
                ));
            }
        }

        return new self($tree, $resources, $byName, $privileges, $bundles);
    }

    /**
     * What $file says about the resources brought with it, and nothing else.
     *
     * @param list<ResourceName> $brought
     *
     * @return array{array<string, non-empty-list<Privilege>>, array<string, bool>}
     */
    private static function describedIn(string $file, array $brought): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException(sprintf(
                'There is no %s, so this build does not say what may be asked about.',
                $file,
            ));
        }

        $declared = Neon::decodeFile($file);
        if (!is_array($declared)) {
            throw new \RuntimeException(sprintf('%s does not describe any resource.', $file));
        }

        $own = [];
        foreach ($brought as $resource) {
            $own[self::nameOf($resource)] = $resource;
        }

        $privileges = [];
        $bundles = [];

        foreach ($declared as $name => $description) {
            $resource = $own[(string) $name] ?? null;
            if (!$resource instanceof ResourceName) {
                throw new \RuntimeException(sprintf(
                    '%s describes %s, which is not one of the resources it brings: %s. A file speaks for the '
                        . 'resources brought with it and for nobody else\'s.',
                    $file,
                    var_export($name, true),
                    implode(', ', array_keys($own)),
                ));
            }

            if (!is_array($description)) {
                throw new \RuntimeException(sprintf(
                    "%s says '%s: %s'; a resource is described by what may be asked of it.",
                    $file,
                    self::nameOf($resource),
                    get_debug_type($description),
                ));
            }

            $unknown = array_diff(array_map(strval(...), array_keys($description)), self::KEYS);
            if ($unknown !== []) {
                throw new \RuntimeException(sprintf(
                    "%s describes '%s' by %s, and a resource is described by %s and nothing else. A key nobody "
                        . 'reads is a sentence that looks like a rule - a misspelt bundle reads as no bundle, and '
                        . 'its only symptom is a right somebody never gets. What a resource falls under is not a '
                        . 'key either: it is the name, up to its last dot.',
                    $file,
                    self::nameOf($resource),
                    implode(', ', $unknown),
                    implode(', ', self::KEYS),
                ));
            }

            $privileges[self::nameOf($resource)] = self::privilegesIn($description, $resource, $file);
            $bundles[self::nameOf($resource)] = self::bundleIn($description, $resource, $file);
        }

        foreach (array_keys($own) as $name) {
            if (!array_key_exists($name, $privileges)) {
                throw new \RuntimeException(sprintf(
                    "%s says nothing about '%s', and every resource has to be described where the others brought "
                        . 'with it are: registration walks the resources, so one that is missing here would be '
                        . 'registered with nothing that may be asked of it.',
                    $file,
                    $name,
                ));
            }
        }

        return [$privileges, $bundles];
    }

    /**
     * Yes, no, or not said - which is no. Anything else is refused rather than
     * read as one of them, because the one it would be read as by accident is
     * the widest thing the file can say.
     *
     * @param array<array-key, mixed> $description
     */
    private static function bundleIn(array $description, ResourceName $resource, string $file): bool
    {
        $bundle = $description[self::BUNDLE] ?? false;
        if (!is_bool($bundle)) {
            throw new \RuntimeException(sprintf(
                "%s gives '%s' a %s of %s; whether the whole of a resource may be granted is yes or no.",
                $file,
                self::nameOf($resource),
                self::BUNDLE,
                var_export($bundle, true),
            ));
        }

        return $bundle;
    }

    /**
     * @param array<array-key, mixed> $description
     *
     * @return non-empty-list<Privilege>
     */
    private static function privilegesIn(array $description, ResourceName $resource, string $file): array
    {
        $declared = $description[self::PRIVILEGES] ?? null;
        if (!is_array($declared) || $declared === []) {
            throw new \RuntimeException(sprintf(
                "%s does not say what may be asked of '%s'. A resource nothing may be asked of is a resource "
                    . 'no rule can be written about, which is a way of leaving it out that looks like describing it.',
                $file,
                self::nameOf($resource),
            ));
        }

        $privileges = [];
        foreach ($declared as $name) {
            $privilege = is_string($name) ? Privilege::tryFrom($name) : null;
            if (!$privilege instanceof Privilege) {
                throw new \RuntimeException(sprintf(
                    "%s offers %s on '%s', and the privileges this build has are: %s. "
                        . 'Nette does not check a privilege against anything, so a name it does not know is a rule '
                        . 'nobody will ever match rather than an error anybody will ever see.',
                    $file,
                    var_export($name, true),
                    self::nameOf($resource),
                    implode(', ', array_map(static fn(Privilege $p): string => $p->value, Privilege::cases())),
                ));
            }

            $privileges[] = $privilege;
        }

        return $privileges;
    }
}
