<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Finance;

use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Nette\Application\UI\Template;
use Nette\Forms\Controls\BaseControl;
use Trilobit\Core\Domain\Finance\PriceRefused;
use Trilobit\Core\Domain\Finance\TaxRate;
use Trilobit\Core\Domain\Finance\TaxRateKind;
use Trilobit\Core\Domain\Finance\VatRate;
use Trilobit\Core\Finance\TaxRateConflict;
use Trilobit\Core\Finance\TaxRateRefused;
use Trilobit\Core\Finance\TaxRates;
use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Presentation\Form\FormFactory;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * The rates of tax of a business: the list of them, the form a rate is added
 * or changed with, and - on a rate's own page - making it the default,
 * archiving it and restoring it.
 *
 * **The pages offer; Trilobit\Core\Finance\TaxRates decides.** A button that
 * is not drawn is a courtesy to the person reading the page: every act goes
 * through the service, which asks again for its own right, so a form posted by
 * hand ends where the service says - as a sentence on the screen.
 *
 * **What each view needs is declared where it is.** Seeing the rates is the
 * floor for the whole presenter, and all a rate's own page needs, as a
 * person's page among the people: on it, each act is drawn for the right it
 * asks - changing the rate and making it the default for `edit`, archiving
 * and restoring it for `delete` - so somebody who may archive and nothing more
 * archives from there. Adding a rate is narrower and says so above its action.
 * Each form is made only on the action that draws it (Nette's
 * Requires(actions)), so posted to another it is not there.
 *
 * **A write refused because somebody else changed the rates meanwhile is
 * answered with a redirect** (TaxRateConflict): Doctrine closed the entity
 * manager, so the page is not drawn from it, and the sentence goes with the
 * redirect as a flash message.
 *
 * Archiving, restoring and making a rate the default are each a submit of a
 * form of their own and never a link, as taking a role away is: a link that
 * changes something is a link something else may follow.
 */
#[Needs(Resource::TaxRate, Privilege::View)]
final class TaxRatesPresenter extends AdminPresenter
{
    /** The list of rates, by the name its parameters carry in the address: `?rates-name=...`. */
    private const string LIST = 'rates';

    private const string FORM = 'rate';

    private ?TaxRate $edited = null;

    public function __construct(
        private readonly TaxRates $rates,
        private readonly TaxRateListingFactory $listings,
        private readonly FormFactory $forms,
    ) {
        parent::__construct();
    }

    #[Needs(Resource::TaxRate, Privilege::Add)]
    public function actionAdd(): void
    {
        $this->setView('edit');
    }

    /**
     * A rate's own page, for anybody who may see the rates. A rate of another
     * business is not found here - the same answer as no rate at all.
     */
    public function actionEdit(int $id): void
    {
        $rate = $this->rates->find($id);
        if (!$rate instanceof TaxRate) {
            $this->error('This business has no rate of tax by that number.');
        }

        $this->edited = $rate;
        $this->getComponent(self::FORM)->setDefaults([
            'name' => $rate->name(),
            'rate' => $rate->rate()->percent(),
            'country' => $rate->country() ?? '',
            'kind' => $rate->kind()?->value,
        ]);
    }

    public function renderDefault(): void
    {
        $template = $this->template();
        $template->pageTitle = 'Tax rates';
        $template->headline = 'Tax rates';
        $template->lead = 'The rates of tax this business charges. The default is the one a new price starts with; '
            . 'an archived rate is kept for whatever is priced at it, and offered for nothing new.';
        $template->sectionUrl = $this->link('Signpost:default');
        $template->addUrl = $this->getUser()->isAllowed(Resource::TaxRate, Privilege::Add) ? $this->link('add') : '';

        // Made before the template is drawn, not by it: an answer to Naja
        // holds the snippets of the controls that exist by then.
        $this->getComponent(self::LIST);
    }

    public function renderEdit(): void
    {
        $template = $this->template();
        $rate = $this->edited;

        $template->listUrl = $this->link('default');
        $template->sectionUrl = $this->link('Signpost:default');

        if (!$rate instanceof TaxRate) {
            $template->pageTitle = 'A new rate of tax';
            $template->headline = 'A new rate of tax';
            $template->lead = 'The first rate a business adds becomes its default.';

            return;
        }

        $mayEdit = $this->getUser()->isAllowed(Resource::TaxRate, Privilege::Edit);
        $mayDelete = $this->getUser()->isAllowed(Resource::TaxRate, Privilege::Delete);

        $template->isNew = false;
        $template->mayEdit = $mayEdit && !$rate->isArchived();
        $template->country = $rate->country() ?? '';
        $template->kind = $rate->kind()?->label() ?? '';
        $template->pageTitle = $rate->label();
        $template->headline = $rate->label();
        $template->lead = match (true) {
            $rate->isArchived() => 'Archived: kept for whatever is priced at it, and offered for nothing new.',
            $mayEdit => 'Changing the rate changes what everything priced at it costs with tax, and the change is written to the audit trail.',
            default => 'One of the rates of tax this business charges, as it stands.',
        };
        $template->isDefault = $rate->isDefault();
        $template->isArchived = $rate->isArchived();
        $template->kept = match (true) {
            !$rate->isArchived() => $mayEdit ? '' : 'You may see this rate and not change it.',
            $mayDelete => 'An archived rate is kept as it was. Restore it to change it.',
            default => 'An archived rate is kept as it was.',
        };
        $template->mayMakeDefault = $mayEdit && !$rate->isDefault() && !$rate->isArchived();
        $template->mayArchive = $mayDelete && $this->rates->whyNotArchive($rate) === null;
        $template->mayRestore = $mayDelete && $rate->isArchived();
        $template->defaultNote = $this->defaultNote($rate, $mayEdit, $mayDelete);
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? TaxRatesTemplate::class);
    }

    /** Every rate of this business, filtered and paged from the address; see TaxRateListing. */
    #[Requires(actions: ['default'])]
    protected function createComponentRates(): TaxRateListing
    {
        return $this->listings->create();
    }

    /** A rate written: the new one on `add`, the one shown on `edit`. */
    #[Requires(actions: ['add', 'edit'])]
    protected function createComponentRate(): Form
    {
        $form = $this->forms->createVertical();
        $form->getElementPrototype()->setAttribute('data-testid', 'tax-rate-form');

        $form->addText('name', 'Name')
            ->setRequired('A rate of tax needs a name: it is what the rate is chosen by.')
            ->addRule(Form::MaxLength, 'A name is at most %d characters long.', TaxRate::MAX_NAME_LENGTH)
            ->setOption('description', 'What the rate is chosen by, such as Reduced rate.')
            ->setHtmlAttribute('data-testid', 'tax-rate-name');
        $form->addText('rate', 'Rate (%)')
            ->setRequired('A rate of tax needs its rate; 0 is one.')
            ->setOption('description', 'A percentage, with at most two decimal places: 21, 12.5.')
            ->setHtmlAttribute('inputmode', 'decimal')
            ->setHtmlAttribute('data-testid', 'tax-rate-rate');
        $form->addText('country', 'Country')
            ->setMaxLength(2)
            ->setOption('description', 'The two letters of the country it is a rate of, such as CZ; empty for none.')
            ->setHtmlAttribute('data-testid', 'tax-rate-country');
        $form->addSelect('kind', 'Kind', TaxRateKind::choices())
            ->setPrompt('--- no kind')
            ->setOption('description', 'Which of the country\'s rates it is.')
            ->setHtmlAttribute('data-testid', 'tax-rate-kind');
        $form->addSubmit('save', 'Save')->setHtmlAttribute('data-testid', 'tax-rate-save');

        $form->onSuccess[] = $this->save(...);

        return $form;
    }

    /** The rate shown, made the business's default. */
    #[Requires(actions: ['edit'])]
    protected function createComponentDefault(): Form
    {
        $form = $this->forms->create();
        $form->addSubmit('choose', 'Make it the default');
        $form->onSuccess[] = $this->makeDefault(...);

        return $form;
    }

    /** The rate shown, archived. */
    #[Requires(actions: ['edit'])]
    protected function createComponentArchive(): Form
    {
        $form = $this->forms->create();
        $form->addSubmit('archive', 'Archive');
        $form->onSuccess[] = $this->archive(...);

        return $form;
    }

    /** The rate shown, offered again. */
    #[Requires(actions: ['edit'])]
    protected function createComponentRestore(): Form
    {
        $form = $this->forms->create();
        $form->addSubmit('restore', 'Restore');
        $form->onSuccess[] = $this->restore(...);

        return $form;
    }

    private function save(Form $form): void
    {
        $values = $form->getValues('array');
        $rate = $this->rateOf($form);
        if (!$rate instanceof VatRate) {
            return;
        }

        $name = is_string($values['name'] ?? null) ? $values['name'] : '';
        $country = is_string($values['country'] ?? null) ? $values['country'] : null;
        $kind = is_string($values['kind'] ?? null) ? TaxRateKind::tryFrom($values['kind']) : null;

        try {
            if ($this->edited instanceof TaxRate) {
                $saved = $this->rates->revise($this->edited->id() ?? 0, $name, $rate, $country, $kind);
                $this->flashMessage(sprintf('%s is saved.', $saved->label()));
            } else {
                $saved = $this->rates->add($name, $rate, $country, $kind);
                $this->flashMessage(sprintf(
                    $saved->isDefault() ? '%s is added, and is the default: it is the first rate of this business.' : '%s is added.',
                    $saved->label(),
                ));
            }
        } catch (TaxRateConflict $conflict) {
            $this->flashMessage($conflict->getMessage(), 'danger');
            $this->redirect('this');
        } catch (TaxRateRefused $refused) {
            // Beside the form where it is drawn; on a page that draws no form
            // for the person reading - the rate's page, read by somebody who
            // may not change it - as a sentence above the page instead.
            $formIsDrawn = $this->edited instanceof TaxRate
                ? $this->getUser()->isAllowed(Resource::TaxRate, Privilege::Edit)
                : $this->getUser()->isAllowed(Resource::TaxRate, Privilege::Add);
            if (!$formIsDrawn) {
                $this->flashMessage($refused->getMessage(), 'danger');
                $this->redirect('this');
            }

            $form->addError($refused->getMessage());

            return;
        }

        $this->redirect('default');
    }

    private function makeDefault(): void
    {
        $rate = $this->rateShown();

        try {
            $this->rates->makeDefault($rate->id() ?? 0);
            $this->flashMessage(sprintf('%s is the default now.', $rate->label()));
        } catch (TaxRateRefused $refused) {
            $this->flashMessage($refused->getMessage(), 'danger');
        }

        $this->redirect('this');
    }

    private function archive(): void
    {
        $rate = $this->rateShown();

        try {
            $this->rates->archive($rate->id() ?? 0);
            $this->flashMessage(sprintf('%s is archived: it is offered for nothing new.', $rate->label()));
        } catch (TaxRateRefused $refused) {
            $this->flashMessage($refused->getMessage(), 'danger');
        }

        $this->redirect('this');
    }

    private function restore(): void
    {
        $rate = $this->rateShown();

        try {
            $this->rates->restore($rate->id() ?? 0);
            $this->flashMessage(sprintf('%s is offered again.', $rate->label()));
        } catch (TaxRateRefused $refused) {
            $this->flashMessage($refused->getMessage(), 'danger');
        }

        $this->redirect('this');
    }

    /**
     * What the page says about the rate shown being the default, or '' when
     * it is not: the state for anybody, and how to archive it only to
     * somebody who may do both acts that takes.
     */
    private function defaultNote(TaxRate $rate, bool $mayEdit, bool $mayDelete): string
    {
        if (!$rate->isDefault()) {
            return '';
        }

        return $mayEdit && $mayDelete
            ? 'This is the default: a new price starts with it, and the default cannot be archived. Make another rate the default first to archive this one.'
            : 'This is the default: a new price starts with it, and the default cannot be archived.';
    }

    /** The rate typed, or null with the reason beside the field. */
    private function rateOf(Form $form): ?VatRate
    {
        $control = $form->getComponent('rate');
        $value = $control instanceof BaseControl ? $control->getValue() : null;

        try {
            return VatRate::ofPercent(is_string($value) ? $value : '');
        } catch (PriceRefused $refused) {
            if ($control instanceof BaseControl) {
                $control->addError($refused->getMessage());
            }

            return null;
        }
    }

    private function rateShown(): TaxRate
    {
        return $this->edited ?? $this->error('This is done on the page of the rate it is about.');
    }

    private function template(): TaxRatesTemplate
    {
        $template = $this->getTemplate();
        if (!$template instanceof TaxRatesTemplate) {
            throw new \LogicException(sprintf('The template of %s has to be a %s.', self::class, TaxRatesTemplate::class));
        }

        return $template;
    }
}
