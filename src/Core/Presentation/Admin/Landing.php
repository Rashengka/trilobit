<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

use Trilobit\Core\Security\Landlords;

/**
 * Where the administration begins for the person making this request.
 *
 * There are two scopes and therefore two beginnings, and the application has
 * to know which one it is looking at in four places: after somebody signs in,
 * behind the mark in the banner that every page offers as the way back, in the
 * first entry of the administration bar, which is where the way back was
 * looked for, and at /admin itself, which is the address of the administration
 * a person knows and therefore the one they type. Sending the administrator of
 * the installation to the overview of a business would be sending them to a
 * page they are refused on - a working application behaving like a broken one,
 * and on the very first page a fresh installation shows the account it was set
 * up with.
 *
 * **It is one class because it is one decision.** Written into the sign-in
 * page and again into the layout, the two would answer differently the first
 * time either was changed, and the one that was left behind would be the one
 * somebody meets after signing in. Every place above therefore asks this and
 * none of them keeps an answer of its own.
 *
 * **What it answers is where somebody belongs, and that is not the same claim
 * as what they may open.** For every role holding a right in a section of the
 * administration the two coincide, because such a right opens the
 * administration it is a section of (see src/Core/Security/permissions.neon).
 * They come apart for a role made only of pieces outside it -
 * `app.redirection` is under the application and not under the
 * administration - which is sent to an overview it may not open. Anything
 * that turns this answer into a link therefore still has to ask the second
 * question as well - the bar does, through
 * Trilobit\Core\Admin\Menu\ReachableMenu::wouldOpen(), and draws no way back
 * where there is none to draw.
 *
 * **What it is not is a page that changes by who is looking.** Both
 * destinations exist for everybody and both say the same thing to whoever
 * opens them; what this chooses is which of them somebody is sent to, which is
 * a redirect and visible in the address bar. A single page that quietly drew
 * one thing for one reader and another for another is the shape decision B3
 * refuses (.ai/plans/01h-opravneni.md: "a section that changes what is visible
 * according to who is signed in is exactly the kind of silent difference we
 * avoid").
 *
 * The destinations are absolute - a leading colon - because they are followed
 * from presenters in more than one module, and a relative one would be
 * resolved inside whichever module was asking.
 */
final readonly class Landing
{
    /** Where somebody who administers one business begins: the overview of it. */
    public const string OVERVIEW = ':Core:Admin:Dashboard:default';

    /** Where somebody who administers the installation begins: the signpost of their own section. */
    public const string INSTALLATION = ':Core:Installation:Signpost:default';

    public function __construct(
        private Landlords $landlords,
    ) {}

    /**
     * Nobody signed in gets the overview, which is the answer that costs
     * nothing: it is where the sign-in page sends somebody once they are, and
     * the gate on it is what turns a visitor round if they are not.
     */
    public function forThisPerson(): string
    {
        return $this->landlords->isLandlord() ? self::INSTALLATION : self::OVERVIEW;
    }
}
