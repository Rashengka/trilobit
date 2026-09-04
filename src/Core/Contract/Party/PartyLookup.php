<?php

declare(strict_types=1);

namespace Trilobit\Core\Contract\Party;

/**
 * What a module has on hand when it asks Core whether somebody is already
 * known: whatever contact details it collected, and nothing that presumes a
 * module capable of answering exists.
 *
 * Both fields are optional and a caller may supply either, both, or neither -
 * PartyDirectory::find() is the one that decides what an empty lookup means,
 * not this class.
 */
final readonly class PartyLookup
{
    public function __construct(
        public ?string $email = null,
        public ?string $phone = null,
    ) {}
}
