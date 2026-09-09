<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Session;

use Nette\Application\UI\Presenter;

/**
 * Going out.
 *
 * It is one page for the whole application rather than one per part of it,
 * because there is one identity and one session: ending it is the same act
 * whoever is doing it, and a second way of doing it would be a second thing to
 * keep right. See Trilobit\Core\Routing\SessionRoutes for the address and for
 * why signing *in* did not move with it.
 *
 * **It draws nothing, which is why it extends the framework's presenter** and
 * not the base class of either chrome. Wrapping a redirect in the site's
 * banner and footer would be work done for a page nobody sees; the same
 * reasoning is written out at Trilobit\Core\Presentation\Front\RedirectPresenter.
 *
 * **Where it leaves somebody is the front page, and that follows from the
 * sentence above.** The administration's sign-in page was the obvious
 * destination while this lived inside the administration, and it stops being
 * obvious the moment anybody else can arrive here: it would offer a customer
 * the way into an administration they have nothing to do with. The front page
 * is the one page that belongs to no audience in particular, so it is the one
 * page every audience can be left on.
 *
 * The identity goes with the session rather than being kept for a later "you
 * were signed in as": a browser somebody has signed out of should hold nothing
 * about them.
 */
final class SignOutPresenter extends Presenter
{
    /** As the presenter mapping in config/common.neon names this class. */
    public const string DESTINATION = ':Core:Session:SignOut:default';

    public function actionDefault(): void
    {
        $this->getUser()->logout(clearIdentity: true);
        $this->redirect(':Core:Front:Home:default');
    }
}
