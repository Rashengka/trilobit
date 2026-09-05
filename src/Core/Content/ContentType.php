<?php

declare(strict_types=1);

namespace Trilobit\Core\Content;

/**
 * A kind of content, and the page that draws it.
 *
 * This is the whole of decision R8: the register turns an address into a type
 * and an identifier, and this turns a type into a presenter. Neither step
 * involves the name of a module, so the tree the modules are nested in and the
 * tree the addresses are nested in never touch - which is what lets a page and
 * a product sit beside each other at the root of the site.
 *
 * A module contributes one of these per kind of content it publishes, and a
 * module that is switched off contributes none. An address whose type nothing
 * claims is therefore not routed at all, rather than routed to an error: the
 * row is still in the register, waiting for the module to come back.
 */
final readonly class ContentType
{
    public function __construct(
        /** Namespaced by the module that owns it: `blog.article`. */
        public string $type,
        /** As the presenter mapping names it: `Blog:Front:Article`. */
        public string $presenter,
        public string $action = 'default',
    ) {}

    /** The form a link is generated against, which is how the reverse lookup is keyed. */
    public function destination(): string
    {
        return $this->presenter . ':' . $this->action;
    }
}
