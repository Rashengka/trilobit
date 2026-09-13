<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

/**
 * What a permission question is about, whoever defined it.
 *
 * Core's own resources are Trilobit\Core\Security\Resource. A module brings
 * its own in an enum of its own that implements this, and says so through
 * Trilobit\Core\Security\ResourceProvider - because Core may not name a
 * module (tests/Architecture/CoreKnowsNoModuleTest), so a single enum in Core
 * could not stay the complete list the moment a module needed a resource of
 * its own.
 *
 * **It is an enum and nothing else**, and that is why it extends BackedEnum
 * rather than declaring a method: only an enum can implement it, so a
 * resource is still a case a compiler knows and never a string somebody
 * spelled - which is the whole reason a misspelt privilege or resource is
 * ever noticed. Its value is the path to the resource, `app.administration.x`,
 * and the path is the tree, exactly as for Core's own.
 *
 * The value is a string. The interface cannot say so - an enum backed by an
 * integer is a backed enum too - so Trilobit\Core\Security\PermissionStructure
 * refuses one when it reads what a build brings.
 *
 * Which resources a build has is therefore not one enum's cases any more; it
 * is Trilobit\Core\Security\PermissionStructure::resources(), put together
 * from Core's and those of the modules this build is made of.
 */
interface ResourceName extends \BackedEnum {}
