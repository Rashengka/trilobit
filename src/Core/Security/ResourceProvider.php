<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

/**
 * A module says which resources it brings, and what may be asked of each, by
 * registering a service that implements this and carries the tag
 * Trilobit\Core\DI\CoreExtension::TAG_RESOURCE_PROVIDER.
 *
 * It is the arrangement routes, menus and content types already use, and for
 * the same reason: Core holds no list of modules. A module that is switched
 * off registers no service, so its resources are not in the build - a role
 * naming one keeps the piece in its row, and it holds again the day the
 * module comes back (see Trilobit\Core\Security\Grant::parse()).
 *
 * The two halves are the two halves Core's own resources have: the enum is
 * which resources there are, the file is what may be asked of each and
 * whether the whole of one may be granted. The file speaks for these
 * resources and for nobody else's - see
 * Trilobit\Core\Security\PermissionStructure.
 */
interface ResourceProvider
{
    /**
     * The resources this module brings - its enum's cases, all of them.
     *
     * @return list<ResourceName>
     */
    public function resources(): array;

    /** The absolute path to the NEON file describing them, written the way src/Core/Security/permissions.neon is. */
    public function structureFile(): string;
}
