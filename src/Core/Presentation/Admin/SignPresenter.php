<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

use Nette\Application\UI\Form;
use Nette\Application\UI\Template;
use Nette\Security\AuthenticationException;
use Trilobit\Core\Security\Authenticator;
use Trilobit\Core\Security\OpenToEverybody;

/**
 * Coming in.
 *
 * It is the one page of the administration that answers to somebody who is not
 * signed in, which is the whole of why the declaration above it is the open
 * one and why it is the only page in the build carrying it.
 *
 * **Going out is not here, and the asymmetry is the point.** Arriving differs
 * by audience - an administrator arrives in the administration, somebody
 * buying something will one day arrive somewhere else - so signing in belongs
 * to the part of the application somebody is signing into. Leaving does not:
 * there is one identity and one session, so ending it is one act with one
 * address for the whole application. See
 * Trilobit\Core\Presentation\Session\SignOutPresenter.
 *
 * The form carries no CSRF token of its own. nette/forms 3.3 deprecates its
 * token control as redundant beside the check the framework now makes on every
 * signal - the request has to come from this site, read off the browser's
 * Sec-Fetch-Site header - and adding one back would be a second mechanism to
 * keep in step with the first.
 *
 * Whatever went wrong, the message is one sentence that does not say which of
 * the ways it was; see Trilobit\Core\Security\Authenticator.
 */
#[OpenToEverybody(because: 'coming in is what somebody who cannot be asked to sign in first does')]
final class SignPresenter extends AdminPresenter
{
    public function actionIn(): void
    {
        if ($this->getUser()->isLoggedIn()) {
            $this->redirect($this->landing());
        }
    }

    public function renderIn(): void
    {
        $template = $this->getTemplate();
        if (!$template instanceof SignInTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s.',
                self::class,
                SignInTemplate::class,
            ));
        }

        $template->pageTitle = 'Sign in';
        $template->headline = 'Sign in';
        $template->lead = 'The administration of this installation.';
        $template->errors = array_map(strval(...), $this->getComponent('signIn')->getOwnErrors());
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used. Naming the class is what lets the
     * template declare {templateType} and be analysed rather than guessed at.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? SignInTemplate::class);
    }

    protected function createComponentSignIn(): Form
    {
        $form = new Form();
        $form->addEmail('email', 'Address')
            ->setRequired('An address is needed to sign in.')
            ->setHtmlAttribute('autocomplete', 'username')
            ->setHtmlAttribute('autofocus');
        $form->addPassword('password', 'Password')
            ->setRequired('A password is needed to sign in.')
            ->setHtmlAttribute('autocomplete', 'current-password');
        $form->addSubmit('send', 'Sign in');

        $form->onSuccess[] = $this->signIn(...);

        return $form;
    }

    /**
     * Nette hands a success handler the form and its values; the values are
     * taken off the form here instead, so that this method has one argument
     * whose type says what it is rather than a second one that does not.
     */
    private function signIn(Form $form): void
    {
        $values = $form->getValues('array');
        $address = isset($values['email']) && is_string($values['email']) ? $values['email'] : '';
        $secret = isset($values['password']) && is_string($values['password']) ? $values['password'] : '';

        try {
            $this->getUser()->login($address, $secret);
        } catch (AuthenticationException) {
            $form->addError(Authenticator::REFUSAL);

            return;
        }

        // Where somebody lands depends on which administration they have, and
        // it has to: the account a fresh installation is set up with holds
        // nothing in any business, so the overview of one is the page it would
        // be refused on. See Trilobit\Core\Presentation\Admin\Landing.
        $this->redirect($this->landing());
    }
}
