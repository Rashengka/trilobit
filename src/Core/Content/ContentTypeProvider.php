<?php

declare(strict_types=1);

namespace Trilobit\Core\Content;

/**
 * A module says which kinds of content it publishes, and which page draws
 * each, by registering a service that implements this and carries the tag
 * Trilobit\Core\DI\CoreExtension::TAG_CONTENT_TYPE_PROVIDER.
 *
 * It is the same arrangement as the routes and the menu entries, and it is one
 * for the same reason: Core holds no list of modules and no condition on one
 * being enabled. A module that is switched off registers no service, so its
 * kinds of content are simply not in the build - and the addresses that lead
 * to them stop being routed, without anything having to know the module's
 * name.
 */
interface ContentTypeProvider
{
    /** @return list<ContentType> */
    public function contentTypes(): array;
}
