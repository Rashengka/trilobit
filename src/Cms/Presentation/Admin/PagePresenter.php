<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Admin;

use Nette\Application\UI\Form;
use Nette\Application\UI\Template;
use Nette\Http\IResponse;
use Trilobit\Cms\Application\Page\Pages;
use Trilobit\Cms\Domain\Page\Page;
use Trilobit\Cms\Domain\Page\PageStatus;
use Trilobit\Core\Content\Categories;
use Trilobit\Core\Content\PathRefused;
use Trilobit\Core\Content\Placement;
use Trilobit\Core\Content\PublicPath;
use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * Writing pages: the list of them, and the form one is written in.
 *
 * **Where a page answers is two fields of this form.** It has to be, because it
 * is not a field of the page: the address lives in Core's register, which is
 * the one table an address is unique in across every module (decision R2). The
 * form asks for the category the page is filed under and the last part of the
 * address, and never for a whole address (decisions C2 and C4): a deeper
 * address typed by hand is a row whose parents do not exist. The register
 * puts the two together and refuses what it will not hold - taken, reserved,
 * a slash or a dot in the last part - and every refusal arrives here as a
 * sentence shown on the form rather than as a stack trace.
 *
 * **The last part can be asked for.** handleSuggestSegment() hands the title to
 * the register and returns what saving would accept; the browser only carries
 * the question, so the rules exist once.
 *
 * **An address is only moved when somebody moved it.** A page saved with the
 * category and last part the form offered stays where it is - which matters
 * for an address typed out whole before categories existed: the form cannot
 * offer it as a category and a last part, says so, and leaves it alone until
 * one of the two is changed.
 *
 * **Publishing is a field too, and not a button of its own.** An editor
 * changing a page and publishing it is one act with one outcome; two buttons
 * would be two, and the second would be the one that is forgotten.
 *
 * **Deleting is a submit and never a link.** A link that deletes is a link
 * something else may follow - a prefetch, a crawler, a mistyped address - and
 * this one takes the page's addresses with it.
 *
 * **What each of those needs is declared where it is done.** Reading the list
 * is the floor for the whole presenter; writing a new page and rewriting an
 * existing one are narrower and say so above their own actions. The class-level
 * pair is not decoration beside them: a submitted form arrives through
 * processSignal(), which asks nothing of any method, so the floor and the
 * action of the same request are the two things standing in front of every
 * form on this page - and in front of the suggestion, which is a signal too.
 *
 * **Deleting is asked about in the handler**, because it is not a view. It is
 * a second button on the form of a page somebody may already edit, and an
 * attribute cannot tell which button was pressed - so the one place that knows
 * a deletion is happening is the place that has to ask. **Exit condition:** a
 * declaration that can be written above a signal, at which point the question
 * moves above delete() and stops being a line inside it.
 */
#[Needs(Resource::Content, Privilege::View)]
final class PagePresenter extends AdminPresenter
{
    private const string FORM = 'page';

    private ?Page $edited = null;

    public function __construct(
        private readonly Pages $pages,
        private readonly Categories $categories,
    ) {
        parent::__construct();
    }

    /** A new page is written in the same form an existing one is; only what happens on save differs. */
    #[Needs(Resource::Content, Privilege::Add)]
    public function actionAdd(): void
    {
        $this->setView('edit');
    }

    #[Needs(Resource::Content, Privilege::Edit)]
    public function actionEdit(int $id): void
    {
        $page = $this->pages->find($id);
        if (!$page instanceof Page) {
            $this->error('No such page.');
        }

        $this->edited = $page;
        $offered = $this->offeredPlacement($page);
        $this->form()->setDefaults([
            'title' => $page->title(),
            'category' => $offered->category,
            'segment' => $offered->segment,
            'perex' => $page->perex(),
            'content' => $page->content(),
            'seoTitle' => $page->seoTitle() === $page->title() ? '' : $page->seoTitle(),
            'seoDescription' => $page->seoDescription(),
            'status' => $page->status()->value,
        ]);
    }

    /**
     * The last part of an address made of $title under $category, as the
     * register would accept it, answered as JSON for the form's button.
     *
     * Whatever cannot be answered comes back as a sentence beside an empty
     * suggestion, so that pressing the button is never met with nothing.
     */
    public function handleSuggestSegment(string $title = '', string $category = ''): void
    {
        try {
            $segment = $this->pages->suggestSegment($title, $category === '' ? null : $category, $this->edited);
            $message = $segment === ''
                ? 'There is nothing in the title to make the last part of an address from; write it by hand.'
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

        $template->pageTitle = 'Pages';
        $template->headline = 'Pages';
        $template->lead = 'Everything this site says in its own words, and where each of it answers.';
        $template->pages = $this->summaries();
        $template->addUrl = $this->link('add');
    }

    public function renderEdit(): void
    {
        $template = $this->template();
        $page = $this->edited;

        $template->isNew = !$page instanceof Page;
        $template->pageTitle = $page instanceof Page ? $page->title() : 'A new page';
        $template->headline = $page instanceof Page ? $page->title() : 'A new page';
        $template->lead = $template->isNew
            ? 'A page starts as a draft, and the address it will answer at is held for it from the moment it is saved.'
            : 'What this page says, where it answers, and whether a visitor may see it.';
        $template->listUrl = $this->link('default');
        $template->suggestUrl = $this->link('suggestSegment!');
        $template->address = $page instanceof Page ? ($this->pages->addressOf($page) ?? '') : '';
        $template->publicUrl = $template->address === ''
            ? ''
            : $this->getHttpRequest()->getUrl()->getBasePath() . $template->address;
        $template->typedAddress = $page instanceof Page && $template->address !== '' && !$this->pages->placementOf($page) instanceof Placement
            ? '/' . $template->address
            : '';
        $template->errors = array_map(strval(...), $this->form()->getOwnErrors());
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used. Naming the class is what lets the
     * template declare {templateType} and be analysed rather than guessed at.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? PagesTemplate::class);
    }

    protected function createComponentPage(): Form
    {
        $form = new Form();
        $form->addText('title', 'Title')
            ->setRequired('A page needs a title.')
            ->setMaxLength(Page::MAX_TITLE_LENGTH);
        $form->addSelect('category', 'Category', $this->categoryChoices())
            ->setPrompt('--- none, at the root of the site');
        $form->addText('segment', 'Last part of the address')
            ->setRequired('A page needs the last part of the address it answers at.');
        $form->addTextArea('perex', 'Lead');
        $form->addTextArea('content', 'Body');
        $form->addText('seoTitle', 'Title for search engines')
            ->setMaxLength(Page::MAX_TITLE_LENGTH);
        $form->addText('seoDescription', 'Description for search engines')
            ->setMaxLength(Page::MAX_DESCRIPTION_LENGTH);
        $form->addSelect('status', 'State', [
            PageStatus::Draft->value => 'Draft, nobody but you sees it',
            PageStatus::Published->value => 'Published',
        ])->setDefaultValue(PageStatus::Draft->value);

        $form->addSubmit('send', 'Save');
        $form->onSuccess[] = $this->save(...);

        if ($this->edited instanceof Page) {
            // Its own handler rather than a branch inside the one above, and
            // with nothing validated: a page is deleted whether or not the
            // form beside the button happens to be filled in correctly.
            $delete = $form->addSubmit('delete', 'Delete this page');
            $delete->setValidationScope([]);
            $delete->onClick[] = $this->delete(...);
        }

        return $form;
    }

    private function save(Form $form): void
    {
        $values = $form->getValues('array');

        $title = $this->text($values, 'title');
        $category = $this->choice($values, 'category');
        $segment = $this->text($values, 'segment');

        try {
            $page = $this->edited;
            if (!$page instanceof Page) {
                $page = $this->pages->create($title, $segment, $category);
            } elseif (!$this->isWhereItWasOffered($page, $category, $segment)) {
                $this->pages->moveTo($page, $segment, $category);
            }

            $this->pages->revise(
                $page,
                $title,
                $this->text($values, 'perex'),
                $this->text($values, 'content'),
                $this->text($values, 'seoTitle'),
                $this->text($values, 'seoDescription'),
            );

            if ($this->text($values, 'status') === PageStatus::Published->value) {
                $this->pages->publish($page);
            } else {
                $this->pages->withdraw($page);
            }
        } catch (PathRefused $refused) {
            // The register's own sentence, which is written for whoever typed
            // the address; see Trilobit\Core\Content\PathRefused.
            $form->addError($refused->getMessage());

            return;
        }

        $this->redirect('default');
    }

    /**
     * Deleting asks for itself, here, rather than above the action.
     *
     * The action this arrives through is `edit`, and being trusted to rewrite
     * a page is not the same as being trusted to take it away - a role
     * assembled out of `app.administration.content:edit` and no `app.administration.content:delete` is exactly the
     * one this pair exists for. The button is still drawn for them: hiding it
     * without refusing it would be the wrong half, and drawing what somebody
     * may not do is a question about menus rather than about gates.
     */
    private function delete(): void
    {
        if (!$this->getUser()->isAllowed(Resource::Content, Privilege::Delete)) {
            $this->error('This is not yours to delete.', IResponse::S403_Forbidden);
        }

        $page = $this->edited
            ?? throw new \LogicException('The delete button is only added to the form while a page is being edited.');

        $this->pages->delete($page);
        $this->redirect('default');
    }

    /**
     * The category and last part the form offers for $page - where it is, or,
     * for an address typed out whole before categories existed, no category
     * and the last segment of it.
     *
     * Saving compares what was submitted against this and moves the page only
     * when the two differ, so that an address the form cannot say is never
     * rewritten by somebody who only changed the title.
     */
    private function offeredPlacement(Page $page): Placement
    {
        $placement = $this->pages->placementOf($page);
        if ($placement instanceof Placement) {
            return $placement;
        }

        $segments = PublicPath::segments($this->pages->addressOf($page) ?? '');

        return new Placement(null, (string) end($segments));
    }

    private function isWhereItWasOffered(Page $page, ?string $category, string $segment): bool
    {
        $offered = $this->offeredPlacement($page);

        return $offered->category === $category && $offered->segment === $segment;
    }

    /**
     * Every category a page may be filed under, by its identifier, named with
     * its address so that two categories called the same are told apart.
     *
     * @return array<string, string>
     */
    private function categoryChoices(): array
    {
        $choices = [];
        foreach ($this->categories->all() as $category) {
            $choices[$category->ref->id] = $category->label . ' (/' . $category->path . ')';
        }

        return $choices;
    }

    /** @return list<PageSummary> */
    private function summaries(): array
    {
        $summaries = [];
        foreach ($this->pages->all() as $page) {
            $id = $page->id();
            if ($id === null) {
                continue;
            }

            $address = $this->pages->addressOf($page);

            $summaries[] = new PageSummary(
                $id,
                $page->title(),
                // Drawn with the leading slash a visitor would type, and said
                // in words where there is none: an empty cell beside a page
                // reads as a page at the root of the site.
                $address === null ? 'no address yet' : '/' . $address,
                $page->isPublished() ? 'Published' : 'Draft',
                $page->isPublished(),
                $this->link('edit', ['id' => $id]),
                $address === null
                    ? ''
                    : $this->getHttpRequest()->getUrl()->getBasePath() . $address,
            );
        }

        return $summaries;
    }

    /**
     * The form on this page, by the one name it is registered under.
     *
     * Its type is not checked here: the analyser reads it off
     * createComponentPage() below, so a component of the wrong type is a static
     * error rather than something to be found at run time.
     */
    private function form(): Form
    {
        return $this->getComponent(self::FORM);
    }

    private function template(): PagesTemplate
    {
        $template = $this->getTemplate();
        if (!$template instanceof PagesTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s.',
                self::class,
                PagesTemplate::class,
            ));
        }

        return $template;
    }

    /**
     * One value of the submitted form as a string.
     *
     * Nette's own types say a control may hand back something else, and a
     * page whose title had quietly become the word "Array" is the kind of
     * thing nobody notices until it is published.
     *
     * @param array<string, mixed> $values
     */
    private function text(array $values, string $field): string
    {
        $value = $values[$field] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * What was chosen in a select with a prompt, or null for the prompt.
     *
     * An identifier made of digits alone comes back from the array the choices
     * are kept in as a number, so it is turned back into the string it is.
     *
     * @param array<string, mixed> $values
     */
    private function choice(array $values, string $field): ?string
    {
        $value = $values[$field] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
