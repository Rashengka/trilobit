<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front\Signpost;

/**
 * A signpost with its address already resolved, ready for a template.
 *
 * Signpost carries a presenter name, because a module may not decide what a URL
 * looks like. Turning one into the other is the router's job and happens in the
 * presenter, so that a template never has to ask - and so that a signpost
 * pointing at a page this build does not have fails while the page is being
 * prepared rather than half-way through rendering it.
 */
final readonly class SignpostLink
{
    public function __construct(
        public string $label,
        public string $href,
        public string $summary,
        public string $testId,
    ) {}
}
