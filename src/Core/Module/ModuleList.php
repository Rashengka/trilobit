<?php

declare(strict_types=1);

namespace Trilobit\Core\Module;

use Nette\Neon\Neon;

/**
 * Which modules this build is made of.
 *
 * There is one source of truth for that - config/modules.neon - and it is read
 * once, at the very start of the boot. Everything downstream follows from the
 * answer: which configuration files get loaded, which compiler extensions get
 * added, what the manifest the asset build reads says. Nothing later gets to
 * decide again.
 *
 * A list that cannot be read is an exception rather than a default. A build
 * that quietly settles on "no modules, then" starts, answers on the homepage,
 * and is missing the shop - and the first person to notice is a customer.
 */
final readonly class ModuleList
{
    private const string PARAMETER_PATH = 'parameters.trilobit.modules';

    /** @param array<string, bool> $modules sorted by name */
    private function __construct(
        private array $modules,
        private string $rootDirectory,
    ) {}

    /**
     * @param array<string, bool> $modules name => switched on
     */
    public static function of(array $modules, string $rootDirectory): self
    {
        foreach (array_keys($modules) as $name) {
            Module::named($name, $rootDirectory);
        }

        ksort($modules);

        return new self($modules, $rootDirectory);
    }

    public static function fromNeon(string $file, string $rootDirectory): self
    {
        if (!is_file($file)) {
            throw new \RuntimeException(sprintf(
                'There is no %s, so this build does not say which modules it is made of.',
                $file,
            ));
        }

        $declared = Neon::decodeFile($file);
        $declared = is_array($declared) ? ($declared['parameters'] ?? null) : null;
        $declared = is_array($declared) ? ($declared['trilobit'] ?? null) : null;
        $declared = is_array($declared) ? ($declared['modules'] ?? null) : null;

        if (!is_array($declared)) {
            throw new \RuntimeException(sprintf('%s has no %s.', $file, self::PARAMETER_PATH));
        }

        $modules = [];
        foreach ($declared as $name => $enabled) {
            if (!is_string($name)) {
                throw new \RuntimeException(sprintf('%s in %s is not a module name.', var_export($name, true), $file));
            }

            // Coercion is refused rather than applied. In NEON `crm: no` is the
            // string "no", which reads as false to a person and as true to
            // anything that coerces - and that is the one mistake in this file
            // that would ship a module nobody meant to ship.
            if (!is_bool($enabled)) {
                throw new \RuntimeException(sprintf(
                    "%s in %s says '%s: %s'; a module is either true or false.",
                    self::PARAMETER_PATH,
                    $file,
                    $name,
                    get_debug_type($enabled),
                ));
            }

            $modules[$name] = $enabled;
        }

        return self::of($modules, $rootDirectory);
    }

    public function rootDirectory(): string
    {
        return $this->rootDirectory;
    }

    /** @return array<string, bool> every declared module, switched on or not */
    public function all(): array
    {
        return $this->modules;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->modules);
    }

    public function isEnabled(string $name): bool
    {
        return $this->modules[$name] ?? false;
    }

    /** @return list<string> */
    public function enabledNames(): array
    {
        return array_keys(array_filter($this->modules));
    }

    /** @return list<Module> */
    public function enabled(): array
    {
        return array_map(
            fn(string $name): Module => Module::named($name, $this->rootDirectory),
            $this->enabledNames(),
        );
    }
}
