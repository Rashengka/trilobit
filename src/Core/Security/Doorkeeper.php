<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

use Nette\Security\User as SignedIn;

/**
 * The one thing a gate is handed, and the one place a new kind of gate is
 * plugged in.
 *
 * A Trilobit\Core\Security\Gate is an attribute, so it is constructed by the
 * engine out of what is written above a page and can be given no services of
 * its own. It therefore has to be handed whatever can answer its kind of
 * question - and if that were the service itself, the interface would name
 * Nette\Security\User today and gain a second parameter the day a gate asked
 * Trilobit\Core\Security\Landlords instead. Every gate already written would
 * have to be edited to answer a question none of them asks.
 *
 * So the interface names this instead. Adding the third kind of gate was a new
 * attribute class and one more method here; nothing above any page changed,
 * and neither did
 * Trilobit\Core\Presentation\Admin\AdminPresenter::checkRequirements(), which
 * knows only that gates admit or do not. That is what the second method below
 * is: Trilobit\Core\Security\AdministersTheInstallation was written against
 * this class and against nothing else.
 *
 * It holds Nette\Security\User rather than
 * Trilobit\Core\Security\Permissions because the framework's own question is
 * the one this project decided to keep - see
 * Trilobit\Core\Security\Authorizator. A gate and a line of application code
 * asking the same thing therefore go the same way and cannot answer
 * differently.
 */
final readonly class Doorkeeper
{
    public function __construct(
        private SignedIn $signedIn,
        /**
         * The other scope, and it is asked of its own service rather than of
         * the framework's user. There is no role, no resource and no privilege
         * in that question, so there is nothing for
         * Nette\Security\User::isAllowed() to walk - and the empty set of roles
         * the identity of such a person carries would answer "no" without
         * asking anybody, which is the shape of a quiet wrong answer.
         */
        private Landlords $landlords,
    ) {}

    /**
     * Whether the person making this request may do this to that.
     *
     * Nette\Security\User::isAllowed() takes mixed and walks the roles on the
     * identity, handing each one to Trilobit\Core\Security\Authorizator; the
     * enums go through it untouched, which is what makes a question asked from
     * an attribute and a question asked from a line of code the same question.
     */
    public function mayDo(Resource $resource, Privilege $privilege): bool
    {
        return $this->signedIn->isAllowed($resource, $privilege);
    }

    /**
     * Whether the person making this request administers the installation
     * itself.
     *
     * It is one line and it is the line that keeps the two scopes apart: the
     * answer comes from Trilobit\Core\Security\Landlords, which reads the row
     * every request, and never from the access list - which has no meaning
     * outside a business and would have to be given a "no business" mode to
     * pretend otherwise.
     */
    public function administersTheInstallation(): bool
    {
        return $this->landlords->isLandlord();
    }
}
