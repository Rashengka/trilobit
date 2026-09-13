<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

/**
 * Which resource falls under which, read from the names and from nothing else.
 *
 * A resource is named by the path to it, so what it falls under is its name
 * up to the last dot: `app.administration.content` falls under
 * `app.administration`, which falls under `app`. There is no second place
 * saying so. A key beside the name would be a sentence that could disagree
 * with the name, and moving a section would be two edits, one of which gets
 * forgotten.
 *
 * **A name whose parent is not itself one of the names is refused**, and so is
 * a name with an empty segment. Either would otherwise be a resource at the
 * top that nobody put there: a right on it would open no door, and nothing
 * would say why. It is refused when the names are read, which is when the
 * structure is - so a build carrying such a name does not start.
 *
 * A parent is always a shorter name than its child, so the tree cannot hold a
 * circle; nothing here has to look for one.
 *
 * It works on strings rather than on Trilobit\Core\Security\Resource because
 * that enum is closed: the one mistake this class exists to refuse is exactly
 * the one the shipped list cannot be made to contain, and a refusal nobody can
 * watch happen is a refusal nobody knows still works.
 */
final readonly class ResourceTree
{
    private const string SEPARATOR = '.';

    /** @param array<string, string|null> $parents what each name falls under, by the name */
    private function __construct(
        private array $parents,
    ) {}

    /** @param list<string> $names */
    public static function of(array $names): self
    {
        $parents = [];
        foreach ($names as $name) {
            if (in_array('', explode(self::SEPARATOR, $name), true)) {
                throw new \RuntimeException(sprintf(
                    "The resource '%s' has an empty segment in its name, so what it falls under would be "
                        . 'decided by where a dot slipped.',
                    $name,
                ));
            }

            $last = strrpos($name, self::SEPARATOR);
            $parent = $last === false ? null : substr($name, 0, $last);
            if ($parent !== null && !in_array($parent, $names, true)) {
                throw new \RuntimeException(sprintf(
                    "The resource '%s' falls under '%s' by its name, and there is no such resource. A right on it "
                        . 'would open nothing above it, so every resource a name passes through has to exist.',
                    $name,
                    $parent,
                ));
            }

            $parents[$name] = $parent;
        }

        return new self($parents);
    }

    public function parentOf(string $name): ?string
    {
        return $this->parents[$name] ?? null;
    }

    /**
     * Everything the name falls under, the nearest first and however high.
     *
     * @return list<string>
     */
    public function ancestorsOf(string $name): array
    {
        $above = [];
        for ($current = $this->parentOf($name); $current !== null; $current = $this->parentOf($current)) {
            $above[] = $current;
        }

        return $above;
    }

    /**
     * Everything under the name, however deep, in the order the names were
     * given. Beginning with the name is not enough - `app.shopping` is not
     * under `app.shop` - so what is compared is the name and a dot.
     *
     * @return list<string>
     */
    public function descendantsOf(string $name): array
    {
        $under = [];
        foreach (array_keys($this->parents) as $candidate) {
            if (str_starts_with($candidate, $name . self::SEPARATOR)) {
                $under[] = $candidate;
            }
        }

        return $under;
    }
}
