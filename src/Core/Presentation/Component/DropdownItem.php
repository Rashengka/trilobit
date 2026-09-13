<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Component;

/**
 * One entry of c-dropdown: an action, or the way somewhere.
 *
 * An entry with an address is a link and one without is a button, for the same
 * reason c-button makes the difference - it is what happens when the entry is
 * used, not how it looks. Either way it is a menuitem of the menu.
 *
 * Separated means a rule is drawn before the entry, parting it and the ones
 * after it from the ones before. On the first entry it draws nothing, there
 * being nothing above it to part from.
 */
final readonly class DropdownItem
{
    public function __construct(
        public string $label,
        public ?string $href = null,
        public bool $separated = false,
        public ?string $testId = null,
    ) {}
}
