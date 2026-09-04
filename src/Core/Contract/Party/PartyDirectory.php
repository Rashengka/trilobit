<?php

declare(strict_types=1);

namespace Trilobit\Core\Contract\Party;

/**
 * The port a module implements when it knows how to look people up - or find
 * out about them for the first time - independently of who is asking.
 *
 * A caller constructs one of these as a plain, always-present dependency
 * rather than an optional one: when no enabled module implements it, Core
 * registers Trilobit\Core\Contract\Party\NullPartyDirectory in its place, so a
 * caller never branches on whether the answer can be trusted to come from
 * anywhere at all - only on whether it came back null.
 */
interface PartyDirectory
{
    /** The reference to a person the lookup matches, or null when nobody claims one. */
    public function find(PartyLookup $lookup): ?PartyRef;

    /**
     * The reference to a person the lookup matches, creating one from the
     * draft first when it does not. Null means no enabled module is able to
     * keep track of people at all, not that the search came back empty.
     */
    public function findOrCreate(PartyLookup $lookup, PartyDraft $draft): ?PartyRef;
}
