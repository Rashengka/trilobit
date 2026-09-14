<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Security;

use Trilobit\Core\Security\ResourceName;
use Trilobit\Core\Security\ResourceProvider;

/**
 * What a module contributes to the permission structure, with the resources
 * and the file handed in - so that a suite can hand over a module that brings
 * one mistake and nothing else.
 */
final readonly class ResourcesOf implements ResourceProvider
{
    /** @param list<ResourceName> $resources */
    public function __construct(
        private array $resources,
        private string $file,
    ) {}

    /** The module of the suites, as it would be written: its enum and its file beside it. */
    public static function demo(): self
    {
        return new self(DemoResource::cases(), __DIR__ . '/demo-permissions.neon');
    }

    public function resources(): array
    {
        return $this->resources;
    }

    public function structureFile(): string
    {
        return $this->file;
    }
}
