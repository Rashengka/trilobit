<?php

declare(strict_types=1);

namespace Trilobit\Core\Contract\Party;

/**
 * What PartyDirectory::findOrCreate() has to create a person from, when
 * PartyLookup did not already find one.
 *
 * It carries the minimum a new record needs and no more: a module deciding
 * what "known" means for the person it just created is free to ask for more
 * afterwards through its own domain, the same way it would for a person it
 * created directly.
 */
final readonly class PartyDraft
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public ?string $phone = null,
    ) {}
}
