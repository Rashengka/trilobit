<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

/**
 * A declaration, written above a page, of who may open it.
 *
 * It is an interface with two implementations today and it is an interface
 * because of the third. Administering the installation is not a resource and a
 * privilege - Trilobit\Core\Security\Landlords answers it, and deliberately
 * not through the access list - so a gate of that kind cannot be a
 * Trilobit\Core\Security\Needs with different arguments. Reading gates as an
 * interface is what lets one be added later without a line being changed
 * either in the declarations already written above pages or in the presenter
 * that enforces them. **Exit condition:** the section of decision B3, which is
 * the first thing to write one.
 *
 * **The two questions are separate on purpose, and the order they are asked in
 * is the point.** Nette calls checkRequirements() for the class before
 * startup(), which is where a visitor who has not signed in used to be sent to
 * the sign-in page; a gate that asked about a permission before it asked about
 * an identity would answer 403 to somebody it should be sending to sign in.
 * Asking requiresIdentity() of every gate first, and admits() of them
 * afterwards, is that order made structural rather than remembered.
 *
 * A gate answers about the request being served and takes no argument saying
 * who is asking. That is the same sentence Trilobit\Core\Security\Permissions
 * says about the tenant, for the same reason: an answer that can be asked
 * about somebody else is one that will eventually be asked about the wrong
 * somebody.
 */
interface Gate
{
    /**
     * Whether somebody has to be signed in before this gate has anything to
     * say.
     *
     * True of every gate that asks what a person may do, because there is no
     * such thing as what nobody may do; false only where a page is declared
     * open, which is the sign-in page and pages like it.
     */
    public function requiresIdentity(): bool;

    /** Whether the person making this request may pass. */
    public function admits(Doorkeeper $doorkeeper): bool;
}
