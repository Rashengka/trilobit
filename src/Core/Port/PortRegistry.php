<?php

declare(strict_types=1);

namespace Trilobit\Core\Port;

/**
 * Which Core ports the enabled modules implement.
 *
 * A port is an interface Core declares and a module may fill in; it is how one
 * module reaches another without naming it. The registry answers the only
 * question Core is allowed to ask about that: is anybody behind this interface
 * in the build we are running.
 *
 * Implementations are collected from the tag
 * Trilobit\Core\DI\CoreExtension::TAG_PORT, whose value is the port interface.
 */
final readonly class PortRegistry
{
    /** @var array<class-string, object> */
    private array $implementations;

    /** @param array<class-string, object> $implementations */
    public function __construct(array $implementations)
    {
        foreach ($implementations as $port => $implementation) {
            if (!$implementation instanceof $port) {
                throw new \InvalidArgumentException(sprintf(
                    'Service of type %s was registered for port %s, which it does not implement.',
                    $implementation::class,
                    $port,
                ));
            }
        }

        $this->implementations = $implementations;
    }

    /** @param class-string $port */
    public function has(string $port): bool
    {
        return isset($this->implementations[$port]);
    }

    /**
     * @template T of object
     * @param class-string<T> $port
     * @return T
     */
    public function get(string $port): object
    {
        $implementation = $this->implementations[$port] ?? null;
        if ($implementation === null) {
            throw new \OutOfBoundsException(sprintf('No enabled module implements the port %s.', $port));
        }

        /** @var T $implementation */
        return $implementation;
    }

    /** @return array<class-string, object> */
    public function all(): array
    {
        return $this->implementations;
    }
}
