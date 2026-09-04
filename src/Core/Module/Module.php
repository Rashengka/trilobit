<?php

declare(strict_types=1);

namespace Trilobit\Core\Module;

use Nette\DI\CompilerExtension;

/**
 * One module, as the name in the configuration implies it.
 *
 * A module is declared by a single word - `blog: true` - and everything else
 * follows from it by convention: the directory it lives in, the namespace its
 * classes are in, the configuration file the boot loads, the compiler
 * extension the boot instantiates. Nothing about a module is configured twice.
 *
 * That is what lets Core work with a module it has never heard of. Core holds
 * no list of module names, no map from a name to a class, and no branch on one
 * being enabled; it holds this rule, and the rule is the same for the fourth
 * module somebody writes as for the first three.
 */
final readonly class Module
{
    /**
     * A module name is one lower-case word, because it becomes a directory
     * name, a namespace segment and a service prefix at once. Refusing
     * anything else here is cheaper than discovering half way through a
     * compile that a name was a path fragment.
     */
    private const string NamePattern = '#^[a-z][a-z0-9]*$#';

    private function __construct(
        public string $name,
        private string $rootDirectory,
    ) {}

    public static function named(string $name, string $rootDirectory): self
    {
        if (preg_match(self::NamePattern, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                "'%s' is not a module name; a module name is one lower-case word starting with a letter.",
                $name,
            ));
        }

        return new self($name, $rootDirectory);
    }

    /** The name as it appears in class names: `blog` becomes `Blog`. */
    public function label(): string
    {
        return ucfirst($this->name);
    }

    public function namespace(): string
    {
        return 'Trilobit\\' . $this->label();
    }

    public function directory(): string
    {
        return $this->rootDirectory . '/' . $this->relativeDirectory();
    }

    /** The directory as the manifest and the asset build refer to it. */
    public function relativeDirectory(): string
    {
        return 'src/' . $this->label();
    }

    /**
     * The configuration a module brings with it: its own services, and later
     * its own entity mapping and migration directory. A switched-off module's
     * file is never loaded, which is what will keep its tables out of reach of
     * the schema tools rather than merely out of the menu.
     */
    public function configFile(): string
    {
        return $this->directory() . '/config/services.neon';
    }

    /**
     * Assembled with implode rather than written out, because a literal with an
     * escaped separator on both sides of a word is indistinguishable from a
     * path on somebody's file server, and the leak guard reports it as one. The
     * guard cannot read PHP, and one that could would be one nobody trusts.
     */
    public function extensionClass(): string
    {
        return implode('\\', [$this->namespace(), 'DI', $this->label() . 'Extension']);
    }

    public function createExtension(): CompilerExtension
    {
        $class = $this->extensionClass();
        if (!class_exists($class) || !is_subclass_of($class, CompilerExtension::class)) {
            throw new \RuntimeException(sprintf(
                "Module '%s' is switched on, but %s is not a %s. Every module is registered by a class of that name in %s/DI.",
                $this->name,
                $class,
                CompilerExtension::class,
                $this->relativeDirectory(),
            ));
        }

        return new $class();
    }
}
