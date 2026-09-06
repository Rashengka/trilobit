<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Admin;

use Nette\Application\UI\Form;
use Nette\Application\UI\Template;
use Trilobit\Cms\Application\Page\Pages;
use Trilobit\Cms\Domain\Menu\MenuItem;
use Trilobit\Cms\Domain\Menu\MenuRepository;
use Trilobit\Cms\Domain\Menu\MenuTarget;
use Trilobit\Cms\Domain\Page\Page;
use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Presentation\Link\Destinations;
use Trilobit\Core\Tenancy\Tenancy;

/**
 * Arranging the menus: the entries there are, and the form one is arranged in.
 *
 * **An entry that leads nowhere is shown here and only here.** The site leaves
 * out anything this build cannot draw, because a visitor following a link into
 * a module that is switched off would meet an error; the administration does
 * the opposite and says so out loud, because whoever arranged the entry is the
 * only person who can decide whether the module should come back or the entry
 * should go. Hiding it in both places would be an entry nobody can find and
 * nobody can remove.
 *
 * **What an entry leads to is chosen as a kind and a value.** The three kinds
 * are not interchangeable - one is a page of this module and holds a foreign
 * key, the other two are text - so the form asks for the kind and refuses a
 * combination the domain has no way to hold; see
 * Trilobit\Cms\Domain\Menu\MenuTarget.
 */
final class MenuPresenter extends AdminPresenter
{
    private const string FORM = 'entry';

    private ?MenuItem $edited = null;

    public function __construct(
        private readonly MenuRepository $entries,
        private readonly Pages $pages,
        private readonly Destinations $destinations,
        private readonly Tenancy $tenancy,
    ) {
        parent::__construct();
    }

    /** A new entry is arranged in the same form an existing one is. */
    public function actionAdd(): void
    {
        $this->setView('edit');
    }

    public function actionEdit(int $id): void
    {
        $entry = $this->entries->find($id);
        if (!$entry instanceof MenuItem) {
            $this->error('No such menu entry.');
        }

        $this->edited = $entry;
        $this->form()->setDefaults([
            'menu' => $entry->menu(),
            'label' => $entry->label(),
            'targetType' => $entry->targetType()->value,
            'target' => $entry->target(),
            'page' => $entry->page()?->id(),
            'parent' => $entry->parent()?->id(),
            'position' => $entry->position(),
            'visible' => $entry->isVisible(),
        ]);
    }

    public function renderDefault(): void
    {
        $template = $this->template();

        $template->pageTitle = 'Menus';
        $template->headline = 'Menus';
        $template->lead = 'What is listed where, and whether this build can draw each of it.';
        $template->entries = $this->summaries();
        $template->addUrl = $this->link('add');
    }

    public function renderEdit(): void
    {
        $template = $this->template();
        $entry = $this->edited;

        $template->isNew = !$entry instanceof MenuItem;
        $template->pageTitle = $entry instanceof MenuItem ? $entry->label() : 'A new entry';
        $template->headline = $entry instanceof MenuItem ? $entry->label() : 'A new entry';
        $template->lead = 'What this entry is called, where it leads, and where it sits.';
        $template->listUrl = $this->link('default');
        $template->errors = array_map(strval(...), $this->form()->getOwnErrors());
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used. Naming the class is what lets the
     * template declare {templateType} and be analysed rather than guessed at.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? MenuTemplate::class);
    }

    protected function createComponentEntry(): Form
    {
        $form = new Form();
        $form->addText('menu', 'Menu')
            ->setRequired('An entry belongs to a menu.')
            ->setMaxLength(MenuItem::MAX_MENU_LENGTH)
            ->setDefaultValue(MenuItem::MAIN);
        $form->addText('label', 'Label')
            ->setRequired('An entry needs something to be called.')
            ->setMaxLength(MenuItem::MAX_LABEL_LENGTH);
        $form->addSelect('targetType', 'Leads to', [
            MenuTarget::Page->value => 'A page of this site',
            MenuTarget::Url->value => 'An address, written out',
            MenuTarget::Route->value => 'A page of one of the modules',
        ])->setDefaultValue(MenuTarget::Page->value);
        $form->addSelect('page', 'Which page', $this->pageChoices())
            ->setPrompt('---');
        $form->addText('target', 'Address or presenter')
            ->setMaxLength(MenuItem::MAX_TARGET_LENGTH);
        $form->addSelect('parent', 'Under', $this->parentChoices())
            ->setPrompt('--- at the top of its menu');
        $form->addInteger('position', 'Position')
            ->setDefaultValue(0);
        $form->addCheckbox('visible', 'Shown on the site')
            ->setDefaultValue(true);

        $form->addSubmit('send', 'Save');
        $form->onSuccess[] = $this->save(...);

        if ($this->edited instanceof MenuItem) {
            $delete = $form->addSubmit('delete', 'Delete this entry');
            $delete->setValidationScope([]);
            $delete->onClick[] = $this->delete(...);
        }

        return $form;
    }

    private function save(Form $form): void
    {
        $values = $form->getValues('array');

        $kind = MenuTarget::tryFrom($this->text($values, 'targetType'));
        if ($kind === null) {
            $form->addError('An entry has to lead to one of the three kinds of thing.');

            return;
        }

        $page = $this->pageOf($values);
        if ($kind === MenuTarget::Page && !$page instanceof Page) {
            $form->addError('Say which page of this site the entry leads to.');

            return;
        }

        $target = $this->text($values, 'target');
        if ($kind !== MenuTarget::Page && $target === '') {
            $form->addError('Say where the entry leads: an address, or a presenter and an action.');

            return;
        }

        $entry = $this->edited ?? $this->newEntry($values, $kind, $page, $target);
        $entry->callItem($this->text($values, 'label'));
        $entry->moveTo($this->number($values, 'position'));
        $this->pointAt($entry, $kind, $page, $target);
        $this->fileUnder($entry, $values);

        if ($this->flag($values, 'visible')) {
            $entry->show();
        } else {
            $entry->hide();
        }

        $this->entries->save($entry);
        $this->redirect('default');
    }

    private function delete(): void
    {
        $entry = $this->edited
            ?? throw new \LogicException('The delete button is only added to the form while an entry is being arranged.');

        $this->entries->remove($entry);
        $this->redirect('default');
    }

    /** @param array<string, mixed> $values */
    private function newEntry(array $values, MenuTarget $kind, ?Page $page, string $target): MenuItem
    {
        $tenant = $this->tenancy->tenant();
        $menu = $this->text($values, 'menu');
        $label = $this->text($values, 'label');

        return match ($kind) {
            MenuTarget::Page => MenuItem::toPage(
                $tenant,
                $menu,
                $label,
                $page ?? throw new \LogicException('An entry leading to a page is only made once a page was chosen.'),
            ),
            MenuTarget::Url => MenuItem::toUrl($tenant, $menu, $label, $target),
            MenuTarget::Route => MenuItem::toRoute($tenant, $menu, $label, $target),
        };
    }

    private function pointAt(MenuItem $entry, MenuTarget $kind, ?Page $page, string $target): void
    {
        if ($kind === MenuTarget::Page) {
            $entry->pointAtPage(
                $page ?? throw new \LogicException('An entry leading to a page is only saved once a page was chosen.'),
            );

            return;
        }

        if ($kind === MenuTarget::Url) {
            $entry->pointAtUrl($target);

            return;
        }

        $entry->pointAtRoute($target);
    }

    /** @param array<string, mixed> $values */
    private function fileUnder(MenuItem $entry, array $values): void
    {
        $parent = $values['parent'] ?? null;
        $entry->fileUnder(is_numeric($parent) ? $this->entries->find((int) $parent) : null);
    }

    /** @param array<string, mixed> $values */
    private function pageOf(array $values): ?Page
    {
        $chosen = $values['page'] ?? null;

        return is_numeric($chosen) ? $this->pages->find((int) $chosen) : null;
    }

    /** @return array<int, string> */
    private function pageChoices(): array
    {
        $choices = [];
        foreach ($this->pages->all() as $page) {
            $id = $page->id();
            if ($id !== null) {
                $choices[$id] = $page->title();
            }
        }

        return $choices;
    }

    /**
     * Every entry but the one being arranged, so that nothing can be filed
     * under itself. Longer loops are refused where they are made rather than
     * here; see Trilobit\Cms\Domain\Menu\MenuItem::fileUnder().
     *
     * @return array<int, string>
     */
    private function parentChoices(): array
    {
        $choices = [];
        foreach ($this->entries->all() as $entry) {
            $id = $entry->id();
            if ($id !== null && $entry !== $this->edited) {
                $choices[$id] = $entry->menu() . ': ' . $entry->label();
            }
        }

        return $choices;
    }

    /** @return list<MenuSummary> */
    private function summaries(): array
    {
        $summaries = [];
        foreach ($this->entries->all() as $entry) {
            $id = $entry->id();
            if ($id === null) {
                continue;
            }

            $summaries[] = new MenuSummary(
                $id,
                $entry->menu(),
                $entry->label(),
                $this->leadsTo($entry),
                $entry->isVisible(),
                $this->isReachable($entry),
                $this->link('edit', ['id' => $id]),
            );
        }

        return $summaries;
    }

    private function leadsTo(MenuItem $entry): string
    {
        return match ($entry->targetType()) {
            MenuTarget::Page => $entry->page()?->title() ?? 'a page that is no longer here',
            MenuTarget::Url, MenuTarget::Route => $entry->target(),
        };
    }

    private function isReachable(MenuItem $entry): bool
    {
        $page = $entry->page();

        return match ($entry->targetType()) {
            MenuTarget::Page => $page instanceof Page && $page->isPublished(),
            MenuTarget::Url => $entry->target() !== '',
            MenuTarget::Route => $this->destinations->drawnByThisBuild($entry->target()),
        };
    }

    /**
     * The form on this page, by the one name it is registered under.
     *
     * Its type is not checked here: the analyser reads it off
     * createComponentEntry() below, so a component of the wrong type is a static
     * error rather than something to be found at run time.
     */
    private function form(): Form
    {
        return $this->getComponent(self::FORM);
    }

    private function template(): MenuTemplate
    {
        $template = $this->getTemplate();
        if (!$template instanceof MenuTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s.',
                self::class,
                MenuTemplate::class,
            ));
        }

        return $template;
    }

    /** @param array<string, mixed> $values */
    private function text(array $values, string $field): string
    {
        $value = $values[$field] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /** @param array<string, mixed> $values */
    private function number(array $values, string $field): int
    {
        $value = $values[$field] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @param array<string, mixed> $values */
    private function flag(array $values, string $field): bool
    {
        return ($values[$field] ?? false) === true;
    }
}
