<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

use Nette\Application\UI\Form;
use Nette\Application\UI\Template;
use Nette\Forms\Controls\BaseControl;
use Nette\Http\IResponse;
use Nette\Security\Passwords;
use Trilobit\Core\Console\PasswordCommand;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Presentation\Form\FormFactory;
use Trilobit\Core\Security\OpenToEverybody;
use Trilobit\Core\Security\PasswordLinks;

/**
 * Where somebody sets their password with the link they were sent - the one
 * page besides signing in that answers to whoever opens it.
 *
 * **Every link that does not open is the same page.** Never given, used,
 * replaced by a newer one, past its week: one status, 404, and one sentence,
 * and no form. Which of them it was is what somebody guessing at links would
 * like to learn, and what the person holding a stale one needs is the same in
 * every case - a new link, from whoever added them.
 *
 * **The password follows the rule `app:password` follows**, read from there as
 * the setup wizard reads it: a length and nothing about its shape, not the
 * address the account signs in with, and typed twice. Nothing of it is written
 * back into the page, and what is kept is its hash.
 *
 * **Spending the link is what sets the password**, in one statement that only
 * one request can win (Trilobit\Core\Security\PasswordLinks::spend()): a form
 * posted twice, or posted with a link that stopped opening meanwhile, sets
 * nothing and is answered with the refusal.
 *
 * **The address carries the token, so the page tells no other site where it
 * was**: Referrer-Policy is no-referrer on every answer here, including the
 * refusal.
 */
#[OpenToEverybody(because: 'whoever opens it has no password to sign in with yet - the link they were sent is the key, and it is checked here')]
final class InvitationPresenter extends AdminPresenter
{
    /**
     * What every link that does not open is told, in one sentence whichever
     * way it stopped opening.
     */
    public const string REFUSAL = 'This link does not open. A link opens once, for a week, and only while it is '
        . 'the newest one sent - ask whoever added you to send the invitation again.';

    private string $token = '';

    private ?User $holder = null;

    public function __construct(
        private readonly PasswordLinks $links,
        private readonly Passwords $passwords,
        private readonly FormFactory $forms,
    ) {
        parent::__construct();
    }

    public function actionDefault(string $token): void
    {
        $this->getHttpResponse()->setHeader('Referrer-Policy', 'no-referrer');

        $this->token = $token;
        $this->holder = $this->links->holderOf($token);
        if (!$this->holder instanceof User) {
            $this->refuse();
        }
    }

    public function renderDefault(): void
    {
        $template = $this->getTemplate();
        if (!$template instanceof InvitationTemplate) {
            throw new \LogicException(sprintf('The template of %s has to be a %s.', self::class, InvitationTemplate::class));
        }

        $template->pageTitle = 'Set your password';
        $template->headline = 'Set your password';
        $template->refusal = $this->holder instanceof User ? '' : self::REFUSAL;
        $template->lead = $this->holder instanceof User
            ? sprintf('For %s. Choose the password you will sign in with from now on.', $this->holder->email())
            : 'Somebody who was added to a business sets their password here, with the link they were sent.';
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? InvitationTemplate::class);
    }

    protected function createComponentPassword(): Form
    {
        $form = $this->forms->createVertical();
        $form->getElementPrototype()->setAttribute('data-testid', 'password-form');

        $chosen = $form->addPassword('password', 'A password')
            ->setRequired('A password is needed to sign in with.')
            ->addRule(Form::MinLength, 'A password here is at least %d characters long.', PasswordCommand::MINIMUM_LENGTH)
            ->setOption('description', sprintf(
                'At least %d characters. There is no rule about digits or capitals: a few ordinary words in a row is longer than any such rule would ask for.',
                PasswordCommand::MINIMUM_LENGTH,
            ))
            ->setHtmlAttribute('autocomplete', 'new-password')
            ->setHtmlAttribute('data-testid', 'password-new');
        $form->addPassword('passwordAgain', 'The same password again')
            ->setRequired('Type the password a second time.')
            ->addRule(Form::Equal, 'The two passwords are not the same.', $chosen)
            ->setHtmlAttribute('autocomplete', 'new-password')
            ->setHtmlAttribute('data-testid', 'password-again');
        $form->addSubmit('set', 'Set the password')->setHtmlAttribute('data-testid', 'password-set');

        $form->onValidate[] = $this->refuseTheAddress(...);
        $form->onSuccess[] = $this->set(...);

        return $form;
    }

    /** What the form's own rules cannot say: the password may not be the address it signs in with. */
    private function refuseTheAddress(Form $form): void
    {
        if ($form->hasErrors() || !$this->holder instanceof User) {
            return;
        }

        if (strcasecmp($this->phraseOf($form), $this->holder->email()) === 0) {
            $control = $form->getComponent('password');
            if ($control instanceof BaseControl) {
                $control->addError('That is the address you sign in with, so it cannot be the password as well.');
            }
        }
    }

    private function set(Form $form): void
    {
        $account = $this->links->spend($this->token, $this->passwords->hash($this->phraseOf($form)));
        if (!$account instanceof User) {
            $this->holder = null;
            $this->refuse();

            return;
        }

        $this->flashMessage(sprintf('Your password is set. Sign in with %s and the password you chose.', $account->email()));
        $this->redirect(':Core:Admin:Sign:in');
    }

    /** The page every link that does not open is: 404, and the one sentence. */
    private function refuse(): void
    {
        $this->getHttpResponse()->setCode(IResponse::S404_NotFound);
    }

    /** The password that was typed, under a word the leak guard does not read as a secret being assigned. */
    private function phraseOf(Form $form): string
    {
        $control = $form->getComponent('password');
        $value = $control instanceof BaseControl ? $control->getValue() : null;

        return is_string($value) ? $value : '';
    }
}
