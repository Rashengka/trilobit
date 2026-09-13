<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Admin;

use Nette\Application\UI\Form;
use Nette\Application\UI\Template;
use Nette\Http\IResponse;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Content\Categories;
use Trilobit\Core\Content\PathRefused;
use Trilobit\Core\Content\Placement;
use Trilobit\Core\Domain\Content\ContentPath;
use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * Arranging categories: the list of them, and the form one is arranged in.
 *
 * **A category is Core's and this is only where it is arranged.** It is a row
 * of the register other addresses are filed under
 * (.ai/plans/11-cms-po-prvnim-proklikani.md, C4), shared with every module
 * that files content into it; this module has the form because it is the
 * first to file anything there. Everything the form does is a call to
 * Trilobit\Core\Content\Categories.
 *
 * **Renaming or moving one moves everything inside it**, and each address that
 * disappears keeps leading to where it went (decision R4) - through the same
 * call a page is moved with, so there is one way addresses move.
 *
 * **Deleting one that still holds something is refused on the form**, with a
 * sentence saying how much is inside. What should happen to those pages is a
 * question for the person deleting, and the form that asks it is plan 16's.
 *
 * **The pairs are the ones pages are gated on**: a category is part of the
 * content of the site, and a resource of its own is due the day a second
 * module files something into categories - until then it would be a right
 * nobody could hold without also holding the pages it is for.
 */
#[Needs(Resource::Content, Privilege::View)]
final class CategoryPresenter extends AdminPresenter
{
    private const string FORM = 'category';

    private ?Address $edited = null;

    public function __construct(
        private readonly Categories $categories,
    ) {
        parent::__construct();
    }

    #[Needs(Resource::Content, Privilege::Add)]
    public function actionAdd(): void
    {
        $this->setView('edit');
    }

    #[Needs(Resource::Content, Privilege::Edit)]
    public function actionEdit(string $id): void
    {
        $category = $this->categories->find($id);
        if (!$category instanceof Address || $category->ref->type !== Categories::TYPE) {
            $this->error('No such category.');
        }

        $this->edited = $category;
        $placement = $this->categories->placementOf($category->path) ?? new Placement(null, $category->path);
        $this->form()->setDefaults([
            'name' => $category->label,
            'parent' => $placement->category,
            'segment' => $placement->segment,
        ]);
    }

    /** The last part of an address made of the name, as the register would accept it; see PagePresenter. */
    public function handleSuggestSegment(string $title = '', string $category = ''): void
    {
        try {
            $segment = $this->categories->suggest($title, $category === '' ? null : $category, $this->edited?->ref->id);
            $message = $segment === ''
                ? 'There is nothing in the name to make the last part of an address from; write it by hand.'
                : '';
        } catch (PathRefused $refused) {
            $segment = '';
            $message = $refused->getMessage();
        }

        $this->sendJson(['segment' => $segment, 'message' => $message]);
    }

    public function renderDefault(): void
    {
        $template = $this->template();

        $template->pageTitle = 'Categories';
        $template->headline = 'Categories';
        $template->lead = 'What pages are filed under. A category\'s address is the beginning of every address inside it.';
        $template->categories = $this->summaries();
        $template->addUrl = $this->link('add');
    }

    public function renderEdit(): void
    {
        $template = $this->template();
        $category = $this->edited;

        $template->isNew = !$category instanceof Address;
        $template->pageTitle = $category instanceof Address ? $category->label : 'A new category';
        $template->headline = $category instanceof Address ? $category->label : 'A new category';
        $template->lead = $template->isNew
            ? 'A category is an address other pages are filed under, and nothing more.'
            : 'Changing where it is filed or the last part of its address moves everything inside it, and every old address keeps leading to the new one.';
        $template->listUrl = $this->link('default');
        $template->suggestUrl = $this->link('suggestSegment!');
        $template->errors = array_map(strval(...), $this->form()->getOwnErrors());
    }

    /** See PagePresenter::createTemplate(). */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? CategoriesTemplate::class);
    }

    protected function createComponentCategory(): Form
    {
        $form = new Form();
        $form->addText('name', 'Name')
            ->setRequired('A category needs a name.')
            ->setMaxLength(ContentPath::MAX_LABEL_LENGTH);
        $form->addSelect('parent', 'Filed under', $this->parentChoices())
            ->setPrompt('--- nothing, at the root of the site');
        $form->addText('segment', 'Last part of the address')
            ->setRequired('A category needs the last part of the address it answers at.');

        $form->addSubmit('send', 'Save');
        $form->onSuccess[] = $this->save(...);

        if ($this->edited instanceof Address) {
            $delete = $form->addSubmit('delete', 'Delete this category');
            $delete->setValidationScope([]);
            $delete->onClick[] = $this->delete(...);
        }

        return $form;
    }

    private function save(Form $form): void
    {
        $values = $form->getValues('array');

        $name = $this->text($values, 'name');
        $parent = $this->choice($values, 'parent');
        $segment = $this->text($values, 'segment');

        try {
            if ($this->edited instanceof Address) {
                $this->categories->revise($this->edited->ref->id, $name, $segment, $parent);
            } else {
                $this->categories->create($name, $segment, $parent);
            }
        } catch (PathRefused $refused) {
            $form->addError($refused->getMessage());

            return;
        }

        $this->redirect('default');
    }

    /**
     * Deleting asks for itself, for the reason set out on
     * Trilobit\Cms\Presentation\Admin\PagePresenter::delete().
     *
     * A refusal is added to the form rather than thrown: the register refuses
     * a category that still holds something, and the person who pressed the
     * button is the one who has to hear it. An error on the form also keeps
     * Nette from carrying on to the save handler once this one returns.
     */
    private function delete(): void
    {
        if (!$this->getUser()->isAllowed(Resource::Content, Privilege::Delete)) {
            $this->error('This is not yours to delete.', IResponse::S403_Forbidden);
        }

        $category = $this->edited
            ?? throw new \LogicException('The delete button is only added to the form while a category is being arranged.');

        try {
            $this->categories->delete($category->ref->id);
        } catch (PathRefused $refused) {
            $this->form()->addError($refused->getMessage());

            return;
        }

        $this->redirect('default');
    }

    /**
     * Every category but the one being arranged and those inside it, so that
     * nothing can be filed under itself. The register refuses that as well;
     * this only keeps it from being offered.
     *
     * @return array<string, string>
     */
    private function parentChoices(): array
    {
        $own = $this->edited?->path;
        $choices = [];
        foreach ($this->categories->all() as $category) {
            if ($own !== null && ($category->path === $own || str_starts_with($category->path, $own . '/'))) {
                continue;
            }

            $choices[$category->ref->id] = $category->label . ' (/' . $category->path . ')';
        }

        return $choices;
    }

    /** @return list<CategorySummary> */
    private function summaries(): array
    {
        $summaries = [];
        foreach ($this->categories->all() as $category) {
            $summaries[] = new CategorySummary(
                $category->ref->id,
                $category->label,
                '/' . $category->path,
                $this->link('edit', ['id' => $category->ref->id]),
            );
        }

        return $summaries;
    }

    /** The form on this page; see PagePresenter::form(). */
    private function form(): Form
    {
        return $this->getComponent(self::FORM);
    }

    private function template(): CategoriesTemplate
    {
        $template = $this->getTemplate();
        if (!$template instanceof CategoriesTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s.',
                self::class,
                CategoriesTemplate::class,
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

    /**
     * What was chosen in a select with a prompt, or null for the prompt; see
     * PagePresenter::choice().
     *
     * @param array<string, mixed> $values
     */
    private function choice(array $values, string $field): ?string
    {
        $value = $values[$field] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
