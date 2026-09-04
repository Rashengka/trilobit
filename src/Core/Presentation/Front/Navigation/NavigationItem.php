<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front\Navigation;

/**
 * One entry of the primary navigation, ready to be rendered.
 *
 * The address is already a URL rather than a presenter name, because the router
 * is the only thing that may turn one into the other and it has done so by the
 * time a template sees this. Trilobit\Core\Presentation\Front\Signpost\Signpost
 * is the other half of the pair: it is what a module contributes, this is what
 * the page draws.
 */
final readonly class NavigationItem
{
    public function __construct(
        public string $label,
        public string $href,
        public bool $current,
        public string $testId,
    ) {}
}
