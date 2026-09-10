<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Error;

use Nette\Application\UI\Template;
use Nette\Http\IResponse;
use Trilobit\Core\Presentation\Front\FrontPresenter;
use Trilobit\Core\Presentation\Session\SignOutPresenter;

/**
 * What somebody sees when a page will not open for them.
 *
 * **It is not an error, and the layer it lives in says so.** A refusal is an
 * answer the application meant to give: the gate asked what this person may do
 * and the answer was no. It used to be raised as a
 * Nette\Application\BadRequestException, which made it an error - and while
 * debug mode is on, `catchExceptions: false` in config/common.neon leaves the
 * framework with no error presenter to forward to, so it rethrows and the
 * error is a stack trace on a developer's screen, which is nothing a visitor
 * can act on. Somebody who is
 * refused everywhere then had no way out at all: the only way of signing out
 * was a link in the administration's own banner, and they never reached a page
 * that drew one.
 *
 * **Which layer, and why not the administration's.** It is drawn in the public
 * chrome, by extending Trilobit\Core\Presentation\Front\FrontPresenter and not
 * Trilobit\Core\Presentation\Admin\AdminPresenter. A page that told somebody
 * they may not be in the administration, and was itself a page of the
 * administration, would be behind the very gate that produced the refusal -
 * refused in turn, forwarded again, and around; nothing but the framework's
 * loop counter would stop it. The public shell belongs to nobody in
 * particular, which is exactly who this page is for.
 *
 * **Why it is reached by a forward and not by an exception.** The gate hands
 * the request to this page instead of raising, so what a visitor gets does not
 * depend on whether the application happens to be catching exceptions:
 * catchExceptions decides what becomes of a throw, and nothing is thrown. The
 * address in the bar is still the one that was asked for and the status is
 * still 403 - the page is the answer to that request rather than a redirect
 * away from it. There is deliberately no route to this presenter: it is
 * somewhere the application sends somebody, never somewhere they ask for, so
 * an address of its own would be an invitation to bookmark being refused.
 *
 * The way out is two links and neither of them leads back into what refused
 * them: ending the session, at the application's own address for it rather
 * than one under the administration
 * (Trilobit\Core\Presentation\Session\SignOutPresenter), and the public site.
 */
final class RefusalPresenter extends FrontPresenter
{
    /** As the presenter mapping in config/common.neon names this class. */
    public const string DESTINATION = ':Core:Error:Refusal:default';

    public function actionDefault(): void
    {
        // The status belongs to the answer and not to the throw that used to
        // carry it: whoever asked - a browser, a crawler, a script - is told
        // this was a refusal, on the address they asked about.
        $this->getHttpResponse()->setCode(IResponse::S403_Forbidden);
    }

    public function renderDefault(): void
    {
        $template = $this->getTemplate();
        if (!$template instanceof RefusalDefaultTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s.',
                self::class,
                RefusalDefaultTemplate::class,
            ));
        }

        // Every gate that can refuse asks who somebody is first, so in this
        // build being refused means being signed in as somebody the page is
        // not for. It is read off the session rather than assumed, because
        // that is a property of the two gates written so far and not a promise
        // the page can make about the third.
        $signedIn = $this->getUser()->isLoggedIn();

        $template->pageTitle = 'Not yours to open';
        $template->headline = 'This is not yours to open.';
        $template->lead = $signedIn
            ? 'The account you are signed in as may not see this page.'
            : 'This page is not open to somebody who is not signed in.';
        $template->signedIn = $signedIn;
        $template->signOutUrl = $this->link(SignOutPresenter::DESTINATION);
        $template->signInUrl = $this->link(':Core:Admin:Sign:in');
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used. Naming the class is what lets the
     * template declare {templateType} and be analysed rather than guessed at.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? RefusalDefaultTemplate::class);
    }
}
