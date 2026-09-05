<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\Tenancy;

/**
 * How a tenant's addresses say which language they are in.
 *
 * All three ways are supported and a tenant uses exactly one of them. That
 * "exactly one" is not a rule anybody checks: it is one column on the tenant,
 * so a combination has nowhere to be written down. A set of flags would have
 * let two of them be on at once, and then something would have had to decide
 * which wins - on every request, for ever.
 *
 * Nothing reads this yet. It is created with the dimension rather than after
 * it because the unique index over an address includes the language, and an
 * index is migrated once or twice depending only on whether the column it
 * covers was there the first time.
 */
enum LanguageStrategy: string
{
    /** The address itself is translated: /kontakt and /contact. */
    case Slug = 'slug';

    /** The language stands in front of the address: /cs/kontakt and /en/kontakt. */
    case Prefix = 'prefix';

    /** The domain the visitor arrived at settles it, and the address is the same on both. */
    case Domain = 'domain';
}
