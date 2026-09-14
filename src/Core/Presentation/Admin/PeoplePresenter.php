<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Nette\Application\UI\Form;
use Nette\Application\UI\Multiplier;
use Nette\Application\UI\Template;
use Nette\Mail\SendException;
use Tracy\Debugger;
use Tracy\ILogger;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Mail\Invitations;
use Trilobit\Core\Presentation\Form\FormFactory;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\PasswordLinks;
use Trilobit\Core\Security\People;
use Trilobit\Core\Security\PeopleRefused;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;
use Trilobit\Core\Tenancy\Tenancy;

/**
 * The people of a business: the list of them, the form somebody new is added
 * with, and the page about one person - what they hold here, taking a role
 * away, giving another, and sending the invitation again.
 *
 * **The pages offer; Trilobit\Core\Security\People decides.** A button that is
 * not drawn is a courtesy to the person reading the page, and nothing more:
 * every act goes through the service, which asks again whatever the page
 * asked, so a form posted by hand, posted from another page, or posted for a
 * role no button was drawn for ends where the service says - as a sentence on
 * the screen, not as a stack trace.
 *
 * **What each view needs is declared where it is.** Seeing who belongs here is
 * the floor for the whole presenter - and, because a submitted form arrives
 * through processSignal(), which asks nothing of any method, the floor is
 * also what stands in front of every form here besides the service. The form
 * for somebody new is narrower and says so above its action.
 *
 * **Mail that did not go is said, and never taken for mail that went.** The
 * person is added whether or not their invitation leaves, because they were:
 * the page they are sent to then says, in the colour of a refusal, that the
 * invitation did not go, and offers to send it again. Why it did not go is
 * written to the log rather than to the page, which is read by whoever added
 * them and not by whoever runs the mail server.
 *
 * **Taking a role away is a submit and never a link**, like deleting a page:
 * a link that removes is a link something else may follow.
 */
#[Needs(Resource::Account, Privilege::View)]
final class PeoplePresenter extends AdminPresenter
{
    /** The list of people, by the name its parameters carry in the address: `?people-name=...`. */
    private const string LIST = 'people';

    private ?User $person = null;

    public function __construct(
        private readonly People $people,
        private readonly Accounts $accounts,
        private readonly PeopleListingFactory $listings,
        private readonly FormFactory $forms,
        private readonly Invitations $invitations,
        private readonly Tenancy $tenancy,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    #[Needs(Resource::Account, Privilege::Add)]
    public function actionAdd(): void {}

    /** Somebody of another business, or of none, is not found here - the same answer as nobody at all. */
    public function actionPerson(int $id): void
    {
        $account = $this->accounts->withId($id);
        if (!$account instanceof User || $this->people->membershipsOf($id) === []) {
            $this->error('Nobody by that number belongs to this business.');
        }

        $this->person = $account;
    }

    public function renderDefault(): void
    {
        $template = $this->template();
        $template->pageTitle = 'People';
        $template->headline = 'People';
        $template->lead = 'Everybody who belongs to this business, and what each of them holds here.';
        $template->addUrl = $this->getUser()->isAllowed(Resource::Account, Privilege::Add) ? $this->link('add') : '';

        // Made before the template is drawn, not by it: an answer to Naja
        // holds the snippets of the controls that exist by then.
        $this->getComponent(self::LIST);
    }

    public function renderAdd(): void
    {
        $template = $this->template();
        $template->pageTitle = 'Add somebody';
        $template->headline = 'Add somebody';
        $template->lead = 'Somebody new is sent a link to set their password with. Somebody who has an account '
            . 'already - in another business - is given the role and signs in as they always have.';
        $template->listUrl = $this->link('default');
        $template->offersARole = $this->people->rolesOffered() !== [];
    }

    public function renderPerson(): void
    {
        $person = $this->personShown();
        $id = $person->id() ?? 0;
        $unchangeable = $this->people->whyNotChange($id);

        $template = $this->template();
        $template->pageTitle = $person->name();
        $template->headline = $person->name();
        $template->lead = $person->email();
        $template->listUrl = $this->link('default');
        $template->personEmail = $person->email();
        $template->personState = PersonSummary::stateOf($person);
        $template->unchangeable = $unchangeable;
        $template->held = array_map(
            fn(Membership $membership): HeldRole => new HeldRole(
                $membership->id() ?? 0,
                $membership->role()->name(),
                $this->people->whyNotTakeAway($membership),
            ),
            $this->people->membershipsOf($id),
        );
        $template->mayGive = $unchangeable === null
            && $this->getUser()->isAllowed(Resource::Account, Privilege::Edit)
            && $this->givableTo($id) !== [];
        $template->mayResend = !$person->hasPassword()
            && $this->getUser()->getId() !== $id
            && $this->getUser()->isAllowed(Resource::Account, Privilege::Add);
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? PeopleTemplate::class);
    }

    /** Everybody who belongs here, filtered and paged from the address; see PeopleListing. */
    protected function createComponentPeople(): PeopleListing
    {
        return $this->listings->create();
    }

    /** Somebody added to this business, holding one of the roles the person adding them may give. */
    protected function createComponentAddition(): Form
    {
        $form = $this->forms->createVertical();
        $form->getElementPrototype()->setAttribute('data-testid', 'people-addition');

        $form->addEmail('email', 'Address')
            ->setRequired('Say which address they sign in with.')
            ->addRule(Form::MaxLength, 'An address is at most %d characters long.', 255)
            ->setHtmlAttribute('data-testid', 'people-addition-email');
        $form->addText('name', 'Name')
            ->setRequired('Say what to call them.')
            ->addRule(Form::MaxLength, 'A name is at most %d characters long.', 255)
            ->setOption('description', 'For somebody new. An account that exists already keeps the name it has.')
            ->setHtmlAttribute('data-testid', 'people-addition-name');
        $form->addSelect('role', 'Role', $this->choicesOf($this->people->rolesOffered()))
            ->setPrompt('--- choose what they hold here')
            ->setRequired('Say what they hold here.')
            ->setHtmlAttribute('data-testid', 'people-addition-role');
        $form->addSubmit('add', 'Add')->setHtmlAttribute('data-testid', 'people-addition-submit');

        $form->onSuccess[] = $this->add(...);

        return $form;
    }

    /** Another role for the person shown, of those the person reading may give and they do not hold. */
    protected function createComponentRole(): Form
    {
        $form = $this->forms->createInline();
        $form->getElementPrototype()->setAttribute('data-testid', 'people-role');

        $form->addSelect('role', 'Role', $this->choicesOf($this->givableTo($this->person?->id() ?? 0)))
            ->setPrompt('--- choose a role')
            ->setRequired('Say which role to give.')
            ->setHtmlAttribute('data-testid', 'people-role-choice');
        $form->addSubmit('give', 'Give')->setHtmlAttribute('data-testid', 'people-role-submit');

        $form->onSuccess[] = $this->give(...);

        return $form;
    }

    /** The invitation, sent again as a new link; the earlier one stops opening. */
    protected function createComponentResend(): Form
    {
        $form = $this->forms->create();
        $form->addSubmit('send', 'Send the invitation again');
        $form->onSuccess[] = $this->resend(...);

        return $form;
    }

    /**
     * One form per role held, named by the membership - `removal-12` - so
     * that each button takes away the role it is drawn beside and nothing
     * else. A form is made for any membership asked for, drawn or not: what
     * decides whether it is taken away is the service, not whether a button
     * was drawn.
     *
     * @return Multiplier<Form>
     */
    protected function createComponentRemoval(): Multiplier
    {
        return new Multiplier(function (string $membership): Form {
            $form = $this->forms->create();
            $form->addSubmit('remove', 'Remove');
            $form->onSuccess[] = fn() => $this->remove((int) $membership);

            return $form;
        });
    }

    private function add(Form $form): void
    {
        $values = $form->getValues('array');

        try {
            $added = $this->people->add(
                is_string($values['email'] ?? null) ? $values['email'] : '',
                is_string($values['name'] ?? null) ? $values['name'] : '',
                is_numeric($values['role'] ?? null) ? (int) $values['role'] : 0,
            );
        } catch (PeopleRefused $refused) {
            $form->addError($refused->getMessage());

            return;
        }

        if ($added->invitation === null) {
            $this->flashMessage(sprintf(
                '%s already had an account, and now belongs to this business as well. They sign in with the password they have.',
                $added->account->email(),
            ));
        } else {
            $this->invite($added->account, $added->invitation);
        }

        $this->redirect('person', ['id' => $added->account->id()]);
    }

    private function give(Form $form): void
    {
        $person = $this->personShown();
        $values = $form->getValues('array');

        try {
            $this->people->give($person->id() ?? 0, is_numeric($values['role'] ?? null) ? (int) $values['role'] : 0);
            $this->flashMessage(sprintf('%s holds another role here now.', $person->name()));
        } catch (PeopleRefused $refused) {
            $this->flashMessage($refused->getMessage(), 'danger');
        }

        $this->redirect('this');
    }

    private function resend(): void
    {
        $person = $this->personShown();

        try {
            $token = $this->people->invitationFor($person->id() ?? 0);
        } catch (PeopleRefused $refused) {
            $this->flashMessage($refused->getMessage(), 'danger');
            $this->redirect('this');
        }

        $this->invite($person, $token);
        $this->redirect('this');
    }

    private function remove(int $membership): void
    {
        $person = $this->personShown();

        try {
            $this->people->remove($membership);
        } catch (PeopleRefused $refused) {
            $this->flashMessage($refused->getMessage(), 'danger');
            $this->redirect('this');
        }

        if ($this->people->membershipsOf($person->id() ?? 0) === []) {
            $this->flashMessage(sprintf('%s no longer belongs to this business.', $person->name()));
            $this->redirect('default');
        }

        $this->flashMessage(sprintf('%s holds one role fewer here now.', $person->name()));
        $this->redirect('this');
    }

    /**
     * Sends $account the link $token opens, and says on the next page whether
     * it went. See the class for why a message that did not go is said in the
     * colour of a refusal, and why its reason goes to the log.
     */
    private function invite(User $account, string $token): void
    {
        try {
            $this->invitations->send(
                $account,
                $this->businessName(),
                $this->link('//:Core:Admin:Invitation:default', ['token' => $token]),
            );
        } catch (SendException $failed) {
            Debugger::log($failed, ILogger::WARNING);
            $this->flashMessage(sprintf(
                'The invitation to %s did not go: the mail server did not take it. They were added all the same - '
                    . 'send the invitation again from this page once mail works.',
                $account->email(),
            ), 'danger');

            return;
        }

        $this->flashMessage(sprintf(
            'The invitation went to %s. The link in it opens once, for %d days.',
            $account->email(),
            PasswordLinks::DAYS,
        ));
    }

    /**
     * The roles the person reading may give $person that $person does not hold
     * yet.
     *
     * @return list<Role>
     */
    private function givableTo(int $person): array
    {
        $held = array_map(static fn(Role $role): ?int => $role->id(), $this->people->rolesHeldBy($person));

        return array_values(array_filter(
            $this->people->rolesOffered(),
            static fn(Role $role): bool => !in_array($role->id(), $held, true),
        ));
    }

    /**
     * @param list<Role> $roles
     *
     * @return array<int, string> the names, by the role's identifier
     */
    private function choicesOf(array $roles): array
    {
        $choices = [];
        foreach ($roles as $role) {
            $id = $role->id();
            if ($id !== null) {
                $choices[$id] = $role->name();
            }
        }

        return $choices;
    }

    private function personShown(): User
    {
        return $this->person ?? $this->error('This is done on the page of the person it is about.');
    }

    private function businessName(): string
    {
        return $this->entityManager->find(Tenant::class, $this->tenancy->current())?->name() ?? '';
    }

    private function template(): PeopleTemplate
    {
        $template = $this->getTemplate();
        if (!$template instanceof PeopleTemplate) {
            throw new \LogicException(sprintf('The template of %s has to be a %s.', self::class, PeopleTemplate::class));
        }

        return $template;
    }
}
