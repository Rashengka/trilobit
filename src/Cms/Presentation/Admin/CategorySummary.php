<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Admin;

/**
 * One category as the list in the administration shows it.
 *
 * Worked out while the page is prepared rather than in the template, for the
 * reason given on Trilobit\Cms\Presentation\Admin\PageSummary: the address
 * comes from the register and the link from the router.
 */
final readonly class CategorySummary
{
    public function __construct(
        /** The register's identifier of it, which is what the form is addressed by. */
        public string $id,
        public string $name,
        /** Where it answers, with the leading slash a visitor would type. */
        public string $address,
        public string $editUrl,
    ) {}
}
