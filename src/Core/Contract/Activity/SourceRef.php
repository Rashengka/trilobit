<?php

declare(strict_types=1);

namespace Trilobit\Core\Contract\Activity;

/**
 * Where an ActivityRecord came from, for whichever screen wants to link back
 * to it - a plain triple rather than a foreign key, for the same reason
 * PartyRef is one: the module on the other end may be switched off by the
 * time somebody reads this back.
 */
final readonly class SourceRef
{
    public function __construct(
        public string $module,
        public string $entity,
        public string $id,
    ) {}
}
