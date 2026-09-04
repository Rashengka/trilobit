<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

use Nette\Application\UI\Form;
use Nette\Application\UI\Template;
use Nette\Security\AuthenticationException;
use Trilobit\Core\Security\Authenticator;

/**
 * Coming in and going out.
 *
 * It is the one page of the administration that answers to somebody who is not
 * signed in, which is the whole of why requiresIdentity() is overridden here
 * and nowhere else.
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
final class SignPresenter extends AdminPresenter
{
    public function actionIn(): void
    {
        if ($this->getUser()->isLoggedIn()) {
            $this->redirect(':Core:Admin:Dashboard:default');
        }
    }

    public function actionOut(): void
    {
        // The identity goes with it rather than being kept for a later "you
        // were signed in as": a browser somebody has signed out of should hold
        // nothing about them.
        $this->getUser()->logout(clearIdentity: true);
        $this->redirect(':Core:Admin:Sign:in');
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

    /** The sign-in page is the one page of the administration that is not behind it. */
    protected function requiresIdentity(): bool
    {
        return false;
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

        $this->redirect(':Core:Admin:Dashboard:default');
    }
}
