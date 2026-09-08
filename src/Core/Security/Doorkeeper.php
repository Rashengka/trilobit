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
 * So the interface names this instead. Adding the third kind of gate is a new
 * attribute class and one more method here; nothing above any page changes,
 * and neither does
 * Trilobit\Core\Presentation\Admin\AdminPresenter::checkRequirements(), which
 * knows only that gates admit or do not. **Exit condition:** the section of
 * decision B3, whose gate needs administersTheInstallation() beside mayDo().
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
}
