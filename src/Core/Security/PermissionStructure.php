<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

use Nette\Neon\Neon;
use Nette\Security\Permission;

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
 * says what a resource falls under and what may be asked of it; which
 * resources there are is the enum's answer and only the enum's. A resource the
 * enum has and the file does not is refused when this is read, so the two
 * cannot drift apart quietly - and quietly is the only way that mistake ever
 * happens, because its symptom appears at somebody else's first question, as
 * an exception rather than as a denial.
 */
final readonly class PermissionStructure
{
    /** Under the project root. It is Core's own file: the structure is the application's, not a deployment's. */
    public const string FILE = 'src/Core/Security/permissions.neon';

    private const string PARENT = 'parent';

    private const string PRIVILEGES = 'privileges';

    /**
     * @param array<string, Resource|null> $parents what each resource falls
     *     under, by the resource's own value
     * @param array<string, non-empty-list<Privilege>> $privileges what may be
     *     asked of each resource, by the resource's own value
     */
    private function __construct(
        private array $parents,
        private array $privileges,
    ) {}

    public static function of(string $rootDirectory): self
    {
        return self::fromNeon($rootDirectory . '/' . self::FILE);
    }

    public static function fromNeon(string $file): self
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

        $parents = [];
        $privileges = [];

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
                    "%s says '%s: %s'; a resource is described by what it falls under and what may be asked of it.",
                    $file,
                    $resource->value,
                    get_debug_type($description),
                ));
            }

            $parents[$resource->value] = self::parentIn($description, $resource, $file);
            $privileges[$resource->value] = self::privilegesIn($description, $resource, $file);
        }

        foreach (Resource::cases() as $resource) {
            if (!array_key_exists($resource->value, $parents)) {
                throw new \RuntimeException(sprintf(
                    "%s says nothing about '%s', and every resource has to be described where the others are: "
                        . 'registration reads the enum, so one that is missing here would be registered '
                        . 'with nothing that may be asked of it.',
                    $file,
                    $resource->value,
                ));
            }
        }

        return new self($parents, $privileges);
    }

    public function parentOf(Resource $resource): ?Resource
    {
        return $this->parents[$resource->value] ?? null;
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
     * Puts every resource into an access list, each one after whatever it
     * falls under - Nette refuses a parent it has not been given yet.
     */
    public function addResourcesTo(Permission $access): void
    {
        foreach ($this->fromTheTopDown() as $resource) {
            $access->addResource($resource->value, $this->parentOf($resource)?->value);
        }
    }

    /**
     * The resources with the ones they fall under first.
     *
     * A resource is taken as soon as what it falls under has been taken, and a
     * pass that takes nothing is a cycle - which is the one shape the checks
     * on reading cannot see, because every name in it is a resource that
     * really exists.
     *
     * @return list<Resource>
     */
    private function fromTheTopDown(): array
    {
        $remaining = Resource::cases();
        $ordered = [];
        $taken = [];

        while ($remaining !== []) {
            $waiting = [];
            foreach ($remaining as $resource) {
                $parent = $this->parentOf($resource);
                if ($parent instanceof Resource && !isset($taken[$parent->value])) {
                    $waiting[] = $resource;

                    continue;
                }

                $ordered[] = $resource;
                $taken[$resource->value] = true;
            }

            if (count($waiting) === count($remaining)) {
                throw new \RuntimeException(sprintf(
                    'These resources fall under each other in a circle, so none of them can be registered first: %s.',
                    implode(', ', array_map(static fn(Resource $r): string => $r->value, $waiting)),
                ));
            }

            $remaining = $waiting;
        }

        return $ordered;
    }

    /** @param array<array-key, mixed> $description */
    private static function parentIn(array $description, Resource $resource, string $file): ?Resource
    {
        $parent = $description[self::PARENT] ?? null;
        if ($parent === null) {
            return null;
        }

        $under = is_string($parent) ? Resource::tryFrom($parent) : null;
        if (!$under instanceof Resource) {
            throw new \RuntimeException(sprintf(
                "%s says '%s' falls under %s, which is not one of the resources this build has: %s.",
                $file,
                $resource->value,
                var_export($parent, true),
                implode(', ', self::values()),
            ));
        }

        if ($under === $resource) {
            throw new \RuntimeException(sprintf("%s says '%s' falls under itself.", $file, $resource->value));
        }

        return $under;
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
