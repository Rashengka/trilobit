<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Nette\Application\UI\Template;
use Nette\Http\IResponse;
use Trilobit\Core\Domain\Navigation\Menu;
use Trilobit\Core\Navigation\Arrangement;
use Trilobit\Core\Navigation\Composition;
use Trilobit\Core\Navigation\Menus;
use Trilobit\Core\Navigation\Navigation;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * Arranging the site's navigation: the order the entries of its contributors
 * stand in, and which contributors are drawn at all
 * (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3, decided 2026-09-13).
 *
 * The default comes from code and changes with a deployment; what is saved
 * here overrules it for this business, and going back to the default forgets
 * what was saved. What each contributor holds is not arranged here - the
 * entries somebody arranged are arranged where they are written, and a
 * module's categories wherever the module keeps them.
 *
 * **Every change is a press, and every press is gated on its own.** Seeing the
 * page is `view` on content; changing anything on it is `change_priority`,
 * which exists for exactly this - "a menu is an ordering: somebody may be
 * trusted to arrange what is there without being trusted to change what it
 * says" (src/Core/Security/permissions.neon). A press is a button of a form,
 * which arrives through the form's own signal and not through an action, so
 * the question is asked where the press is handled, the way deleting a page
 * asks it in the content administration. The buttons are
 * drawn only for somebody who may press them, and the form has them all the
 * same, so a press sent anyway is refused rather than quietly ignored.
 *
 * The buttons are made from the arrangement as it is when the request
 * arrives, and a button is only there where the move can be made; a press
 * that could not be made has no button to arrive at.
 */
#[Needs(Resource::Content, Privilege::View)]
final class NavigationPresenter extends AdminPresenter
{
    private ?Arrangement $arrangement = null;

    public function __construct(
        private readonly Navigation $navigation,
        private readonly Menus $menus,
    ) {
        parent::__construct();
    }

    public function renderDefault(): void
    {
        $template = $this->getTemplate();
        if (!$template instanceof NavigationDefaultTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s.',
                self::class,
                NavigationDefaultTemplate::class,
            ));
        }

        $arrangement = $this->arrangement();
        $mayArrange = $this->mayArrange();

        $rows = [];
        foreach ($arrangement->slots() as $index => $slot) {
            $rows[] = new NavigationRow(
                $index,
                $slot->entry->label,
                $slot->source,
                $mayArrange && $arrangement->canMoveUp($index),
                $mayArrange && $arrangement->canMoveDown($index),
            );
        }

        $sources = [];
        foreach ($arrangement->sources() as $index => $source) {
            $sources[] = new NavigationSourceRow($index, $source->label, $source->hidden);
        }

        $template->pageTitle = 'Navigation';
        $template->headline = 'Navigation';
        $template->lead = 'The order of what the site\'s navigation is made of, and which of it is shown.';
        $template->rows = $rows;
        $template->sources = $sources;
        $template->mayArrange = $mayArrange;
        $template->isComposed = $arrangement->isComposed();
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? NavigationDefaultTemplate::class);
    }

    #[Requires(actions: 'default')]
    protected function createComponentArrangement(): Form
    {
        $form = new Form();
        $arrangement = $this->arrangement();

        foreach ($arrangement->slots() as $index => $slot) {
            if ($arrangement->canMoveUp($index)) {
                $form->addSubmit('up' . $index, 'Move up')->onClick[] = fn() => $this->arrange($arrangement->movedUp($index));
            }

            if ($arrangement->canMoveDown($index)) {
                $form->addSubmit('down' . $index, 'Move down')->onClick[] = fn() => $this->arrange($arrangement->movedDown($index));
            }
        }

        foreach ($arrangement->sources() as $index => $source) {
            if ($source->hidden) {
                $form->addSubmit('show' . $index, 'Show')->onClick[] = fn() => $this->arrange($arrangement->showing($source->key));
            } else {
                $form->addSubmit('hide' . $index, 'Hide')->onClick[] = fn() => $this->arrange($arrangement->hiding($source->key));
            }
        }

        if ($arrangement->isComposed()) {
            // Not called "reset": a button of that name is what a browser takes
            // for the form's own reset, and the press never arrives as this one.
            $form->addSubmit('useDefault', 'Go back to the default')->onClick[] = fn() => $this->arrange(null);
        }

        return $form;
    }

    /** Saves $composition for the site's navigation, or forgets what was saved when it is null. */
    private function arrange(?Composition $composition): void
    {
        if (!$this->mayArrange()) {
            $this->error('Arranging the navigation is not yours to do.', IResponse::S403_Forbidden);
        }

        $menu = $this->menus->namedOrNew(Menu::MAIN);
        if (!$composition instanceof Composition) {
            $menu->useTheDefault();
        } else {
            $menu->recompose($composition);
        }

        $this->menus->save($menu);
        $this->redirect('this');
    }

    private function mayArrange(): bool
    {
        return $this->getUser()->isAllowed(Resource::Content, Privilege::ChangePriority);
    }

    /** Read once per request, so that the buttons and the page agree about what they were made from. */
    private function arrangement(): Arrangement
    {
        return $this->arrangement ??= $this->navigation->arrangementOf(Menu::MAIN);
    }
}
