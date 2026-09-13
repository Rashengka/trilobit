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
 * **Registration walks Resource::cases() rather than this file.** The file
 * says what may be asked of a resource and whether the whole of it may be
 * granted; which resources there are is the enum's answer and only the
 * enum's. A resource the enum has and the file does not is refused when this
 * is read, so the two cannot drift apart quietly - and quietly is the only way
 * that mistake ever happens, because its symptom appears at somebody else's
 * first question, as an exception rather than as a denial.
 *
 * **What a resource falls under is its name**, read by
 * Trilobit\Core\Security\ResourceTree when this is read. The file has no way
 * of saying it: a key for it would be refused like any key nobody reads.
 */
final readonly class PermissionStructure
{
    /** Under the project root. It is Core's own file: the structure is the application's, not a deployment's. */
    public const string FILE = 'src/Core/Security/permissions.neon';

    private const string PRIVILEGES = 'privileges';

    private const string BUNDLE = 'bundle';

    /** Every key a resource may be described by; see fromNeon(). */
    private const array KEYS = [self::PRIVILEGES, self::BUNDLE];

    /**
     * @param array<string, non-empty-list<Privilege>> $privileges what may be
     *     asked of each resource, by the resource's own value
     * @param array<string, bool> $bundles whether the whole of each resource
     *     may be granted, by the resource's own value
     */
    private function __construct(
        private ResourceTree $tree,
        private array $privileges,
        private array $bundles,
    ) {}

    public static function of(string $rootDirectory): self
    {
        return self::fromNeon($rootDirectory . '/' . self::FILE);
    }

    public static function fromNeon(string $file): self
    {
        $tree = ResourceTree::of(self::values());

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

        $privileges = [];
        $bundles = [];

        foreach ($declared as $name => $description) {
            $resource = is_string($name) ? Resource::tryFrom($name) : null;
            if (!$resource instanceof Resource) {
                throw new \RuntimeException(sprintf(
                    '%s describes %s, which is not one of the resources this build has: %s.',
                    $file,
                    var_export($name, true),
                    implode(', ', self::values()),
                ));
            }

            if (!is_array($description)) {
                throw new \RuntimeException(sprintf(
                    "%s says '%s: %s'; a resource is described by what may be asked of it.",
                    $file,
                    $resource->value,
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
                    $resource->value,
                    implode(', ', $unknown),
                    implode(', ', self::KEYS),
                ));
            }

            $privileges[$resource->value] = self::privilegesIn($description, $resource, $file);
            $bundles[$resource->value] = self::bundleIn($description, $resource, $file);
        }

        foreach (Resource::cases() as $resource) {
            if (!array_key_exists($resource->value, $privileges)) {
                throw new \RuntimeException(sprintf(
                    "%s says nothing about '%s', and every resource has to be described where the others are: "
                        . 'registration reads the enum, so one that is missing here would be registered '
                        . 'with nothing that may be asked of it.',
                    $file,
                    $resource->value,
                ));
            }
        }

        foreach (Resource::cases() as $resource) {
            $parent = $tree->parentOf($resource->value);
            if ($parent !== null && !in_array(Privilege::View, $privileges[$parent], true)) {
                throw new \RuntimeException(sprintf(
                    "%s says '%s' falls under '%s', which does not offer view. Any right on '%s' opens what it "
                        . "falls under, and opening is view - so every piece of '%s' would be a way into a "
                        . 'resource that could not be opened.',
                    $file,
                    $resource->value,
                    $parent,
                    $resource->value,
                    $resource->value,
                ));
            }
        }

        return new self($tree, $privileges, $bundles);
    }

    /** @return non-empty-list<Privilege> */
    public function privilegesOf(Resource $resource): array
    {
        return $this->privileges[$resource->value];
    }

    /** Whether asking this of that is a question this build has an answer for. */
    public function offers(Resource $resource, Privilege $privilege): bool
    {
        return in_array($privilege, $this->privileges[$resource->value], true);
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
    public function offersBundle(Resource $resource): bool
    {
        return $this->bundles[$resource->value] ?? false;
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
    public function root(): Resource
    {
        $roots = array_values(array_filter(
            Resource::cases(),
            fn(Resource $resource): bool => $this->tree->ancestorsOf($resource->value) === [],
        ));

        if (count($roots) !== 1) {
            throw new \LogicException(sprintf(
                'Everything has to fall under one resource, and %s is what falls under nothing.',
                $roots === [] ? 'no resource' : implode(', ', array_map(
                    static fn(Resource $resource): string => $resource->value,
                    $roots,
                )),
            ));
        }

        return $roots[0];
    }

    /**
     * Every piece a role could be assembled from in this build.
     *
     * It is what a role that may do everything is made of, and it is derived
     * here rather than written down beside whoever wants it: a second list
     * would go on saying what this file used to offer, and the account holding
     * it would quietly stop being able to reach whatever was added after the
     * list was written.
     *
     * @return list<Grant>
     */
    public function everyPair(): array
    {
        $pairs = [];
        foreach (Resource::cases() as $resource) {
            foreach ($this->privilegesOf($resource) as $privilege) {
                $pairs[] = new Grant($resource, $privilege);
            }
        }

        return $pairs;
    }

    /**
     * Everything that falls under this resource, however deep.
     *
     * It is how far the whole of the resource reaches, granted or denied, and
     * nothing narrower: a concrete privilege on the resource says nothing about
     * what is under it. It is asked for here rather than left to Nette because
     * a parent inside an access list is the route a right taken away further
     * down would come back by; see Trilobit\Core\Security\AccessComposition.
     *
     * @return list<Resource>
     */
    public function descendantsOf(Resource $resource): array
    {
        return array_map(Resource::from(...), $this->tree->descendantsOf($resource->value));
    }

    /**
     * Everything this resource falls under, the nearest first and however
     * high.
     *
     * It is what any right on the resource opens: somebody who may work in a
     * section may get to it, so they may view each thing it is inside.
     *
     * @return list<Resource>
     */
    public function ancestorsOf(Resource $resource): array
    {
        return array_map(Resource::from(...), $this->tree->ancestorsOf($resource->value));
    }

    /**
     * Yes, no, or not said - which is no. Anything else is refused rather than
     * read as one of them, because the one it would be read as by accident is
     * the widest thing the file can say.
     *
     * @param array<array-key, mixed> $description
     */
    private static function bundleIn(array $description, Resource $resource, string $file): bool
    {
        $bundle = $description[self::BUNDLE] ?? false;
        if (!is_bool($bundle)) {
            throw new \RuntimeException(sprintf(
                "%s gives '%s' a %s of %s; whether the whole of a resource may be granted is yes or no.",
                $file,
                $resource->value,
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
    private static function privilegesIn(array $description, Resource $resource, string $file): array
    {
        $declared = $description[self::PRIVILEGES] ?? null;
        if (!is_array($declared) || $declared === []) {
            throw new \RuntimeException(sprintf(
                "%s does not say what may be asked of '%s'. A resource nothing may be asked of is a resource "
                    . 'no rule can be written about, which is a way of leaving it out that looks like describing it.',
                $file,
                $resource->value,
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
                    $resource->value,
                    implode(', ', array_map(static fn(Privilege $p): string => $p->value, Privilege::cases())),
                ));
            }

            $privileges[] = $privilege;
        }

        return $privileges;
    }

    /** @return non-empty-list<string> */
    private static function values(): array
    {
        return array_map(static fn(Resource $resource): string => $resource->value, Resource::cases());
    }
}
