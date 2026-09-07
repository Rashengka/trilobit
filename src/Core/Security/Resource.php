<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

/**
 * What a permission question is about.
 *
 * A resource is sometimes a whole section of the administration and sometimes
 * a single decision inside one page - where a visitor is sent, say. Both are
 * meant: the granularity is whatever the code really asks about, and a rule
 * saying "a resource is a section" would only be worked around the first time
 * one page had two answers in it.
 *
 * **This enum is the complete list, and that is load-bearing.** Nette refuses a
 * resource it has not been given: Permission::checkResource() throws
 * "Resource 'x' does not exist" rather than answering false, and isAllowed()
 * calls it on the way in. So a question about a resource nobody registered is
 * not a denial, it is an exception - a person locked out rather than turned
 * away. Registration therefore reads Resource::cases() and never a list
 * written beside it; two lists would part company, and the day they did, the
 * one that lost would take a user's whole session with it.
 *
 * **The value carries no tenant.** It was tempting to make it - `tenant-7:
 * content` would let a rule be written for one business and nothing else, and
 * Nette's resource parents would even give it the right meaning. It is not
 * done, because the tenant is settled before any of this is asked (see
 * Trilobit\Core\Tenancy\Tenancy) and the access list is built for that tenant
 * alone; putting the tenant in the name as well would make the set of
 * resources grow with the number of businesses, and turn a table that is
 * constant from one build into one that has to be rebuilt whenever a business
 * is added. What a tenant may have of its own is roles - which of these pairs
 * they are made of - and that needs no tenant in here.
 */
enum Resource: string
{
    /** The administration as a whole, and the parent of every section of it. */
    case Administration = 'administration';

    /** Who may sign in, and what they hold. */
    case Account = 'account';

    /** What the public site is drawn from: the pages, the menus, the media. */
    case Content = 'content';

    /**
     * Where a visitor ends up, as distinct from what they are shown. It is a
     * decision inside a page rather than a section of the administration,
     * which is the case the granularity of this enum is deliberately loose
     * enough to hold.
     */
    case Redirection = 'redirection';
}
