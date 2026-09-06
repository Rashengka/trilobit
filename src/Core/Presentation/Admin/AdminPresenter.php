<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

use Nette\Application\UI\Presenter;
use Nette\Application\UI\Template;
use Trilobit\Core\Admin\Menu\Menu;
use Trilobit\Core\Admin\Menu\MenuItem;
use Trilobit\Core\Preference\RememberedPreferences;
use Trilobit\Core\Presentation\Front\Navigation\NavigationItem;
use Trilobit\Core\Security\Identity;

/**
 * The base every administration page is built on, whichever module it belongs
 * to.
 *
 * It does three things: it turns anybody who is not signed in away, it puts the
 * administration layout at the end of the template search, and it fills in what
 * that layout draws itself out of. A module writing an administration page
 * extends this and gets all three by doing so, rather than by remembering to
 * check something in every presenter it writes - which is the kind of check
 * that is eventually forgotten in exactly one place.
 *
 * **Turning away is a redirect, not an error.** A visitor who is not signed in
 * has done nothing wrong, and 403 on a page that exists tells somebody who is
 * guessing that it does. What is deliberately not here is a backlink: with one
 * page in the administration there is nothing to come back to, and a stored
 * request is a session started for every anonymous request to /admin.
 * **Exit condition:** the first module that adds a page worth being returned to
 * after signing in.
 *
 * **Signing in is the whole of the gate.** The roles and permissions an account
 * holds are carried on the identity and shown on the overview, and nothing
 * enforces one yet, because there is no page in the administration that some
 * accounts may open and others may not. **Exit condition:** the first module
 * that contributes one.
 */
abstract class AdminPresenter extends Presenter
{
    private RememberedPreferences $remembered;

    private Menu $menu;

    public function injectAdministration(RememberedPreferences $remembered, Menu $menu): void
    {
        $this->remembered = $remembered;
        $this->menu = $menu;
    }

    /** @return non-empty-list<string> */
    public function formatLayoutTemplateFiles(): array
    {
        $files = parent::formatLayoutTemplateFiles();
        $files[] = __DIR__ . '/templates/@layout.latte';

        return $files;
    }

    /**
     * Whether somebody has to be signed in before this page is drawn.
     *
     * True everywhere but on the sign-in page itself, which is the one page of
     * the administration that has to answer to a visitor who is not signed in.
     */
    protected function requiresIdentity(): bool
    {
        return true;
    }

    protected function startup(): void
    {
        parent::startup();

        if ($this->requiresIdentity() && !$this->getUser()->isLoggedIn()) {
            $this->redirect(':Core:Admin:Sign:in');
        }
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used. Naming the class is what lets the
     * template declare {templateType} and be analysed rather than guessed at.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? AdminTemplate::class);
    }

    protected function beforeRender(): void
    {
        parent::beforeRender();

        $template = $this->getTemplate();
        if (!$template instanceof AdminTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s, because that is what the administration layout is written against.',
                static::class,
                AdminTemplate::class,
            ));
        }

        $identity = $this->getUser()->getIdentity();

        $template->preferences = $this->remembered->forThisRequest();
        $template->preferenceUrl = $this->link(':Core:Preference:Choice:remember');
        $template->overviewUrl = $this->link(':Core:Admin:Dashboard:default');
        $template->signOutUrl = $this->link(':Core:Admin:Sign:out');
        $template->publicSiteUrl = $this->link(':Core:Front:Home:default');
        $template->signedIn = $this->getUser()->isLoggedIn();
        $template->identityName = $identity instanceof Identity ? $identity->displayName() : '';
        $template->identityEmail = $identity instanceof Identity ? $identity->email() : '';
        $template->menu = $template->signedIn ? $this->navigation() : [];
    }

    /**
     * The menu, as addresses rather than as presenter names.
     *
     * The router produces them here rather than in the template, so that an
     * entry pointing at a page this build has no route for fails while the page
     * is being prepared instead of rendering a link that leads nowhere.
     *
     * @return list<NavigationItem>
     */
    private function navigation(): array
    {
        $items = [];
        foreach ($this->menu->items() as $item) {
            $items[] = new NavigationItem(
                $item->label,
                // The leading colon makes the destination absolute; without it
                // Nette would resolve it inside Core, the module this presenter
                // lives in.
                $this->link(':' . $item->destination),
                $this->getName() === $this->presenterOf($item),
                'admin-menu-' . strtolower($item->label),
            );
        }

        return $items;
    }

    /** A menu entry points at an action; the presenter is everything before it. */
    private function presenterOf(MenuItem $item): string
    {
        $separator = strrpos($item->destination, ':');

        return $separator === false ? $item->destination : substr($item->destination, 0, $separator);
    }
}
