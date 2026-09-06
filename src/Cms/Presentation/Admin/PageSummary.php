<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Admin;

/**
 * One page as the list in the administration shows it.
 *
 * The address and the link to the form are worked out while the page is being
 * prepared rather than in the template, for the same reason every other list
 * in this application is: an address comes from the register and a link comes
 * from the router, and a template that asked either of them would be a
 * template holding a service.
 */
final readonly class PageSummary
{
    public function __construct(
        public int $id,
        public string $title,
        /** Where it answers, or a sentence saying it answers nowhere yet. */
        public string $address,
        public string $status,
        public bool $isPublished,
        public string $editUrl,
        /** Where a visitor would see it, or '' while the page has no address yet. */
        public string $publicUrl,
    ) {}
}
