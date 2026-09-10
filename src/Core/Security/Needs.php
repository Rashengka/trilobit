<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

/**
 * What a page needs of whoever opens it, written above the page itself.
 *
 * It reads as a sentence where it is written - `#[Needs(Resource::Content,
 * Privilege::Edit)]` above a presenter or above one of its actions - and that
 * is most of why the pair is spelled out rather than passed as a code or a
 * constant. The other reason is a rule that already existed:
 * Trilobit\Tests\Architecture\PermissionQuestions reads the source for the two
 * enums and neither knows nor cares what surrounds them, so a declaration
 * written this way is a question that falls under
 * "every pair the code asks about is one the structure offers" without that
 * rule being changed. Measured rather than assumed - a pair no NEON file
 * offers is reported from an attribute exactly as it is from a method call.
 *
 * **Above the class and above a method mean different things, and both are
 * meant.** Nette calls checkRequirements() once for the class before anything
 * else happens, and again for each action*(), render*() and handle*() it
 * calls; so a declaration on the class is the floor for everything the
 * presenter does, and one on an action narrows that action alone. They are
 * read together rather than one instead of the other: an action that may be
 * opened by fewer people than the presenter is the case worth writing, and an
 * action open to more of them than the presenter would be a hole in the floor.
 *
 * **The floor is where forms are guarded, and that is a measurement rather
 * than a preference.** A submitted form arrives through
 * Nette\Application\UI\Presenter::processSignal(), which asks nothing of any
 * method - what ran before it is the check made for the class, and the action
 * method of the same request. A presenter whose declarations all sit on
 * render*() methods therefore has its forms guarded by nothing, because
 * render*() is called after the form has already been handled.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class Needs implements Gate
{
    public function __construct(
        public Resource $resource,
        public Privilege $privilege,
    ) {}

    /**
     * There is no such thing as what nobody may do: an access list answers
     * about the roles somebody holds, and somebody who has not signed in holds
     * none. Saying so here rather than in the presenter is what keeps the
     * order right - a visitor meets the sign-in page rather than a refusal.
     */
    public function requiresIdentity(): bool
    {
        return true;
    }

    public function admits(Doorkeeper $doorkeeper): bool
    {
        return $doorkeeper->mayDo($this->resource, $this->privilege);
    }
}
