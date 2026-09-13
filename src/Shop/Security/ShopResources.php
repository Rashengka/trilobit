<?php

declare(strict_types=1);

namespace Trilobit\Shop\Security;

use Trilobit\Core\Security\ResourceProvider;

/**
 * The shop's resources, handed to Core's permission structure - the one way a
 * module's resources get into a build, since Core may not name the module.
 *
 * Registered by Trilobit\Shop\DI\ShopExtension, so a build without the shop
 * has none of them.
 */
final class ShopResources implements ResourceProvider
{
    public function resources(): array
    {
        return ShopResource::cases();
    }

    public function structureFile(): string
    {
        return __DIR__ . '/permissions.neon';
    }
}
