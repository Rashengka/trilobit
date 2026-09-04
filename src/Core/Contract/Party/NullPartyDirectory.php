<?php

declare(strict_types=1);

namespace Trilobit\Core\Contract\Party;

/**
 * What answers PartyDirectory when no enabled module knows anything about
 * people: nobody is ever found, and nobody is ever created.
 *
 * Trilobit\Core\DI\CoreExtension registers this in place of the port when
 * nothing else does, which is the whole of what lets a caller take
 * PartyDirectory as an ordinary constructor dependency instead of writing a
 * null check of its own; see .ai/plans/01a-komunikace-modulu.md §2.
 */
final class NullPartyDirectory implements PartyDirectory
{
    public function find(PartyLookup $lookup): ?PartyRef
    {
        return null;
    }

    public function findOrCreate(PartyLookup $lookup, PartyDraft $draft): ?PartyRef
    {
        return null;
    }
}
