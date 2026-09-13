<?php

declare(strict_types=1);

namespace Trilobit\Core\Config;

use Nette\Utils\FileSystem;
use SensitiveParameterValue;

/**
 * The values a deployment differs by, read from the environment file next to
 * the application and overlaid with the process environment.
 *
 * The file is deliberately dumb: no interpolation, no includes, no inline
 * comments. Every feature such a format grows is a feature somebody has to
 * reproduce when they set the same values through their web server or their
 * container, and then the two disagree.
 *
 * Values live here rather than in a committed NEON file because the committed
 * file would end up carrying a host, a user name and a password, and git keeps
 * those forever.
 *
 * Every value is kept sealed in a SensitiveParameterValue, so a dump of this
 * object - by Tracy, var_dump(), print_r() or var_export() - lists the names
 * and none of the values. It holds everything a deployment has, the machine's
 * whole process environment among it, and it is an object that gets dumped:
 * the debug bar shows every service a request created and an error page shows
 * the arguments of every frame. Deciding value by value which of them is secret
 * would be a guess made on the one object where a wrong guess shows a password;
 * sealing all of them leaves nothing to decide.
 */
final readonly class Environment
{
    /** @var array<string, SensitiveParameterValue> */
    private array $file;

    /** @var array<string, SensitiveParameterValue> */
    private array $process;

    /**
     * @param array<string, string> $file
     * @param array<string, string> $process
     */
    private function __construct(array $file, array $process)
    {
        $this->file = $this->seal($file);
        $this->process = $this->seal($process);
    }

    /**
     * Reads $path if it is there, and lets the process environment win over it,
     * so that a container or a web server can set a value without editing files.
     */
    public static function load(string $path): self
    {
        $process = getenv();

        return new self(self::parse(self::read($path)), $process);
    }

    /** Reads only the file, so that a test can state what it is looking at. */
    public static function fromFile(string $path): self
    {
        return new self(self::parse(self::read($path)), []);
    }

    public static function fromString(string $contents): self
    {
        return new self(self::parse($contents), []);
    }

    /**
     * @param array<string, string> $file
     * @param array<string, string> $process
     */
    public static function fromValues(array $file, array $process = []): self
    {
        return new self($file, $process);
    }

    public function get(string $name): ?string
    {
        return self::open($this->process[$name] ?? $this->file[$name] ?? null);
    }

    /**
     * The value, or $default when this deployment did not set one.
     *
     * It exists so that a configuration file can name a setting and its
     * fallback on the same line, which is where somebody reading that file
     * looks for both. The alternative - a parameter reference that has to
     * resolve - turns one missing entry in .env into a container that cannot
     * be built at all, and most of the application has nothing to do with
     * whichever setting happens to be missing.
     *
     * Emptiness counts as absence, for the reason given on flag().
     */
    public function value(string $name, string $default = ''): string
    {
        $value = $this->get($name);

        return $value === null || $value === '' ? $default : $value;
    }

    /**
     * Any value but the empty one turns a flag on. Absence and emptiness are
     * the same answer on purpose: an unset variable and a variable set to
     * nothing look identical to a web server, so they may not mean different
     * things here.
     */
    public function flag(string $name): bool
    {
        $value = $this->get($name);

        return $value !== null && $value !== '';
    }

    /** @return array<string, string> the values read from the file, as they are written there */
    public function all(): array
    {
        return array_map(static fn(SensitiveParameterValue $value): string => self::open($value) ?? '', $this->file);
    }

    /**
     * @param array<string, string> $values
     * @return array<string, SensitiveParameterValue>
     */
    private function seal(array $values): array
    {
        return array_map(static fn(string $value): SensitiveParameterValue => new SensitiveParameterValue($value), $values);
    }

    private static function open(?SensitiveParameterValue $sealed): ?string
    {
        $value = $sealed?->getValue();

        return is_string($value) ? $value : null;
    }

    private static function read(string $path): string
    {
        return is_file($path) ? FileSystem::read($path) : '';
    }

    /** @return array<string, string> */
    private static function parse(string $contents): array
    {
        $values = [];

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $contents)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $name = trim(substr($line, 0, $separator));
            if ($name === '') {
                continue;
            }

            $values[$name] = self::unquote(trim(substr($line, $separator + 1)));
        }

        return $values;
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) < 2) {
            return $value;
        }

        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
