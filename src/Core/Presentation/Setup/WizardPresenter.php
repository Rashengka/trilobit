<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Setup;

use Nette\Application\BadRequestException;
use Nette\Application\UI\Form;
use Nette\Application\UI\Presenter;
use Nette\Application\UI\Template;
use Nette\Forms\Controls\BaseControl;
use Nette\Http\IResponse;
use Trilobit\Core\Console\PasswordCommand;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Preference\RememberedPreferences;
use Trilobit\Core\Presentation\Form\FormFactory;
use Trilobit\Core\Setup\Installer;
use Trilobit\Core\Setup\Progress;
use Trilobit\Core\Setup\Step;

/**
 * The setup wizard: from a freshly uploaded checkout to an installation
 * somebody can sign in to (.ai/plans/23-instalace-na-zelene-louce.md).
 *
 * It is one page drawn in whichever of three states the database is in -
 * nothing answering, tables to be made, tables with nothing in them yet - and
 * it keeps nothing of its own between requests; see Trilobit\Core\Setup\Step.
 * It ends with the installation's first administrator and its first business,
 * and then it is gone.
 *
 * **It is there for an empty installation only, and gone means not there,
 * not refused (decision O3).** Once the installation holds an account of any
 * kind or a business, every request here - a page, a form posted to it,
 * anything - is answered 404 before any of it is read, which is what an
 * address nobody claims is answered; 403 would tell whoever is probing that
 * there is something here worth trying. An installation that already has
 * something is finished from the command line. It is checked in startup(),
 * which the framework runs before any action and before any signal, so a
 * submitted form never gets as far as its handler.
 *
 * **It signs nobody in (decision O1).** It ends on the sign-in page, so that
 * an identity is only ever made in Trilobit\Core\Security\Authenticator, and so
 * that the first time the chosen password is used is the moment it is shown
 * to work.
 *
 * **It is not a page of the administration**, and it does not extend that
 * base class. It answers before there is anybody to sign in, at a host no
 * business claims (see Trilobit\Core\Tenancy\TenantFromHost), possibly with no
 * database to ask anything of - and the administration's chrome asks the
 * database who is signed in on every page. Its gate is the data rather than a
 * declaration: nothing on it opens for anybody once it has served its one
 * purpose.
 *
 * The forms carry no token of their own, like the sign-in form: nette/forms
 * refuses a submission that does not come from this site, read off the
 * browser's Sec-Fetch-Site header.
 */
final class WizardPresenter extends Presenter
{
    /** The simple installation: the administrator runs the business as well. */
    private const string BOTH = 'both';

    /** The separated one: somebody else runs the business. */
    private const string INSTALLATION_ONLY = 'installation';

    private Step $step = Step::Done;

    public function __construct(
        private readonly Progress $progress,
        private readonly Installer $installer,
        private readonly FormFactory $forms,
        private readonly RememberedPreferences $remembered,
    ) {
        parent::__construct();
    }

    public function renderDefault(): void
    {
        $template = $this->wizardTemplate();

        $template->pageTitle = 'Set up';
        $template->preferences = $this->remembered->forThisRequest();
        $template->preferenceUrl = $this->link(':Core:Preference:Choice:remember');
        $template->setupUrl = $this->link('this');
        $template->step = $this->step;
        $template->host = $this->host();
        $template->attempt = $this->progress->attempt();

        // Not a page that worked: whatever answers this - a person, a monitor,
        // a proxy deciding whether to keep it - is told the service is not
        // there yet rather than that everything is fine.
        if ($this->step === Step::DatabaseUnreachable) {
            $this->getHttpResponse()->setCode(IResponse::S503_ServiceUnavailable);
        }
    }

    /** Where every request here starts; see the class for why an installation that is not empty is answered 404 from here. */
    protected function startup(): void
    {
        parent::startup();

        $this->step = $this->progress->step();
        if ($this->step === Step::Done) {
            throw new BadRequestException('No route for HTTP request.', IResponse::S404_NotFound);
        }
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? WizardTemplate::class);
    }

    protected function createComponentInstall(): Form
    {
        $form = $this->forms->createVertical();
        $form->getElementPrototype()->setAttribute('data-testid', 'setup-install');
        $form->addSubmit('install', 'Install')->setHtmlAttribute('data-testid', 'setup-install-submit');

        $form->onSuccess[] = $this->install(...);

        return $form;
    }

    /**
     * The account that administers the installation, and the business it
     * starts with.
     *
     * The password follows the rule `app:password` follows, read from there so
     * that the two cannot drift: a length and nothing about its shape, and not
     * the address the account signs in with. It is asked twice, because
     * hidden input is the one kind where a typo cannot be seen. Nothing of it
     * is written back into the page, and nothing keeps it but its hash.
     *
     * Which of the two installations this is has no answer chosen in advance.
     * Being both is a decision (.ai/plans/23-instalace-na-zelene-louce.md, on
     * what joining the two scopes costs) - a radio button already set would
     * make it for whoever did not look.
     */
    protected function createComponentAdministrator(): Form
    {
        $form = $this->forms->createVertical();
        $form->getElementPrototype()->setAttribute('data-testid', 'setup-administrator');

        $form->addEmail('email', 'Your address')
            ->setRequired('An address is needed to sign in with.')
            ->addRule(Form::MaxLength, 'An address is at most %d characters long.', 255)
            ->setHtmlAttribute('autocomplete', 'username')
            ->setHtmlAttribute('data-testid', 'setup-email');
        $form->addText('name', 'Your name')
            ->setRequired('Say what to call you.')
            ->addRule(Form::MaxLength, 'A name is at most %d characters long.', 255)
            ->setHtmlAttribute('autocomplete', 'name')
            ->setHtmlAttribute('data-testid', 'setup-name');
        $password = $form->addPassword('password', 'A password')
            ->setRequired('A password is needed to sign in with.')
            ->addRule(
                Form::MinLength,
                'A password here is at least %d characters long.',
                PasswordCommand::MINIMUM_LENGTH,
            )
            ->setOption('description', sprintf(
                'At least %d characters. There is no rule about digits or capitals: a few ordinary words in a row is longer than any such rule would ask for.',
                PasswordCommand::MINIMUM_LENGTH,
            ))
            ->setHtmlAttribute('autocomplete', 'new-password')
            ->setHtmlAttribute('data-testid', 'setup-password');
        $form->addPassword('passwordAgain', 'The same password again')
            ->setRequired('Type the password a second time.')
            ->addRule(Form::Equal, 'The two passwords are not the same.', $password)
            ->setHtmlAttribute('autocomplete', 'new-password')
            ->setHtmlAttribute('data-testid', 'setup-password-again');
        $form->addText('business', 'What your business is called')
            ->setRequired('Say what your business is called.')
            ->addRule(Form::MaxLength, 'A name is at most %d characters long.', Tenant::MAX_NAME_LENGTH)
            ->setHtmlAttribute('data-testid', 'setup-business');
        $form->addRadioList('scope', 'Who runs the business', [
            self::BOTH => 'I run the business myself as well',
            self::INSTALLATION_ONLY => 'Somebody else runs the business',
        ])
            ->setRequired('Say who runs the business: running it as well is a decision, so it is not assumed.')
            ->setOption('description', 'Either way you administer the installation. Somebody else is given the business with bin/trilobit app:account --tenant.');
        $form->addSubmit('finish', 'Finish')->setHtmlAttribute('data-testid', 'setup-finish');

        $form->onValidate[] = $this->refuseWhatCannotBe(...);
        $form->onSuccess[] = $this->finish(...);

        return $form;
    }

    private function install(Form $form): void
    {
        // A form posted to a step that is already past - the page was open in
        // a second tab, or somebody ran the migrations meanwhile - only draws
        // the step that is next.
        if ($this->step === Step::Install) {
            $this->installer->install();
        }

        $this->redirect('this');
    }

    /**
     * What the form's own rules cannot say. Asked only on the step it belongs
     * to, and only of a form its own rules let through, whose values can be
     * read; the rest is said on the next submission, beside whatever is left.
     *
     * An address already taken or a host already claimed cannot happen here:
     * the wizard is only there while the installation holds no account and no
     * business, and the installer asks that again with the claim held.
     */
    private function refuseWhatCannotBe(Form $form): void
    {
        if ($this->step !== Step::FirstAdministrator || $form->hasErrors()) {
            return;
        }

        $values = $this->valuesOf($form);

        if (strcasecmp($values['phrase'], $values['email']) === 0) {
            $this->control($form, 'password')->addError('That is the address the account signs in with, so it cannot be the password as well.');
        }
    }

    private function finish(Form $form): void
    {
        if ($this->step !== Step::FirstAdministrator) {
            $this->redirect('this');
        }

        $values = $this->valuesOf($form);

        $made = $this->installer->complete(
            $values['email'],
            $values['name'],
            $values['phrase'],
            $values['business'],
            $this->host(),
            $values['scope'] === self::BOTH,
        );

        // Somebody else finished in the same moment, or a command made an
        // account or a business meanwhile. Either way the installation is not
        // empty any more, so this request is answered as every request after
        // it will be.
        if (!$made) {
            throw new BadRequestException('No route for HTTP request.', IResponse::S404_NotFound);
        }

        $this->flashMessage('The installation is ready. Sign in with the address and the password you chose.');
        $this->redirect(':Core:Admin:Sign:in');
    }

    /**
     * What was sent, each as a string. The password is under "phrase", the
     * word the leak guard does not read as a secret being assigned.
     *
     * @return array{email: string, name: string, phrase: string, business: string, scope: string}
     */
    private function valuesOf(Form $form): array
    {
        $values = $form->getValues('array');
        $text = static fn(string $key): string => isset($values[$key]) && is_string($values[$key]) ? $values[$key] : '';

        return [
            'email' => $text('email'),
            'name' => $text('name'),
            'phrase' => $text('password'),
            'business' => $text('business'),
            'scope' => $text('scope'),
        ];
    }

    private function control(Form $form, string $name): BaseControl
    {
        $control = $form->getComponent($name);
        if (!$control instanceof BaseControl) {
            throw new \LogicException(sprintf('The form %s has no control called %s.', $form->getName(), $name));
        }

        return $control;
    }

    /** The host the first business answers at: the one this page was opened at, as a request arrives at it. */
    private function host(): string
    {
        return strtolower($this->getHttpRequest()->getUrl()->getHost());
    }

    private function wizardTemplate(): WizardTemplate
    {
        $template = $this->getTemplate();
        if (!$template instanceof WizardTemplate) {
            throw new \LogicException(sprintf('The template of %s has to be a %s.', self::class, WizardTemplate::class));
        }

        return $template;
    }
}
