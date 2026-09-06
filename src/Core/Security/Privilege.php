<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

/**
 * What may be asked of a resource, as one set shared by all of them.
 *
 * One set rather than a set per resource, because `edit` means the same thing
 * about an article as about a product, and a set per resource would make the
 * rule "this role may edit everything" impossible to write down. Which of them
 * a given resource actually offers is said in
 * src/Core/Security/permissions.neon, so a pair that means nothing - sending a
 * page somewhere - is refused there rather than by nobody.
 *
 * **This enum is the only reason a misspelt privilege is ever noticed.** Nette
 * checks a role and it checks a resource, and it does not check a privilege:
 * Permission::setRule() calls checkRole() for every role and checkResource()
 * for every resource, then normalises the privileges into an array without
 * looking at them, and there is no checkPrivilege() or addPrivilege() anywhere
 * in the class. So a typo in a role or a resource raises an exception, and a
 * typo in a privilege writes a rule nobody will ever ask about, or asks a
 * question no rule will ever answer - and either way the answer is a quiet
 * "no" that looks exactly like a decision. Naming them here is what turns that
 * into a name a compiler knows.
 *
 * A privilege says what happens to the data, never what set it off. There is
 * deliberately no general "something was clicked": an operation that reaches
 * for one has a name of its own waiting to be used - publish, archive,
 * approve, recalculate - and that name works across resources exactly as
 * `edit` does. A privilege named after its trigger cannot be withheld in
 * parts, so a role allowed one action is allowed all of them.
 *
 * `delete` is the everyday one and it is the reversible one; `purge` is the
 * one that does not come back. That way round on purpose: the dangerous
 * operation gets the conspicuous word, so that somebody putting a role
 * together out of these cannot pick the irreversible one thinking it is the
 * ordinary one. How the reversal is implemented is not this vocabulary's
 * business - a method may well be called softDelete(), but a permission is
 * read by whoever assembles a role, and they are deciding what a person may
 * do rather than how a row is stored.
 */
enum Privilege: string
{
    /** Opening a page and reading what is on it. */
    case View = 'view';

    case Add = 'add';

    case Edit = 'edit';

    /** The everyday removal, and the one the application undertakes to be able to undo. */
    case Delete = 'delete';

    /** Removal that does not come back. */
    case Purge = 'purge';

    case Export = 'export';

    /** Sending something out of the application - a message, a document, a file. */
    case Send = 'send';

    /** Changing the order things are in. */
    case ChangePriority = 'change_priority';

    /** Overruling where a visitor is sent, rather than being sent there. */
    case ForceRedirect = 'force_redirect';
}
