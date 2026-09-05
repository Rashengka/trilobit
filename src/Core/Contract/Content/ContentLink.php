<?php

declare(strict_types=1);

namespace Trilobit\Core\Contract\Content;

/**
 * A link to a piece of content another module owns: where it is, and what to
 * call it.
 *
 * Both halves come from the module that owns the content, because both can
 * only be answered there. The address is in Core's own register, but a row in
 * it outlives the module that wrote it - a build without that module still has
 * the row and has nothing to draw at it, so a link made from the register
 * alone would lead to a page that is not routed.
 */
final readonly class ContentLink
{
    public function __construct(
        public string $url,
        public string $label,
    ) {}
}
