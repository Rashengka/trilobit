<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

use Nette\Application\UI\Presenter;
use Nette\Application\UI\Template;
use Nette\Http\IResponse;
use Trilobit\Core\Admin\Menu\MenuItem;
use Trilobit\Core\Admin\Menu\ReachableMenu;
use Trilobit\Core\Preference\RememberedPreferences;
use Trilobit\Core\Presentation\Component\SignpostLink;
use Trilobit\Core\Presentation\Front\Navigation\NavigationItem;
use Trilobit\Core\Security\Doorkeeper;
use Trilobit\Core\Security\Gate;
use Trilobit\Core\Security\Identity;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\OpenToEverybody;

/**
 * The base every administration page is built on, whichever module it belongs
 * to.
 *
 * It does three things: it enforces what each page declared about who may open
 * it, it puts the administration layout at the end of the template search, and
 * it fills in what that layout draws itself out of. A module writing an
 * administration page extends this and gets all three by doing so, rather than
 * by remembering to check something in every presenter it writes - which is
 * the kind of check that is eventually forgotten in exactly one place.
 *
 * **Nothing opens that did not say who may open it.** A page carrying no
 * Trilobit\Core\Security\Gate raises where it would have been drawn, and the
 * sentence it raises with says what to write. It is a mistake in the source
 * rather than a visitor doing something they may not, so it is loud rather
 * than a refusal - and it is caught before that by
 * Trilobit\Tests\Architecture\EveryAdministrationViewIsGatedTest, which asks
 * the same question of every view in the build. What must never happen is the
 * third possibility: a page nobody declared anything about quietly opening.
 *
 * **Turning away is a redirect, not an error.** A visitor who is not signed in
 * has done nothing wrong, and 403 on a page that exists tells somebody who is
 * guessing that it does. What is deliberately not here is a backlink: with one
 * page in the administration there is nothing to come back to, and a stored
 * request is a session started for every anonymous request to /admin.
 * **Exit condition:** the first module that adds a page worth being returned to
 * after signing in.
 */
abstract class AdminPresenter extends Presenter
{
    private RememberedPreferences $remembered;

    private ReachableMenu $menu;

    private Landing $landing;

    private Doorkeeper $doorkeeper;

    public function injectAdministration(
        RememberedPreferences $remembered,
        /**
         * The menu already filtered down to what this person may open, rather
         * than Trilobit\Core\Admin\Menu\Menu itself. Both drawings below take
         * it from here, which is what makes the bar and a section's signpost
         * one answer rather than two that agree until somebody changes one.
         */
        ReachableMenu $menu,
        Landing $landing,
    ): void {
        $this->remembered = $remembered;
        $this->menu = $menu;
        $this->landing = $landing;
    }

    /**
     * Its own inject method rather than an argument of the one above, because
     * it is the only thing here that runs before the page does. Whoever comes
     * looking for what enforces the gates finds one name and one method.
     */
    public function injectGate(Doorkeeper $doorkeeper): void
    {
        $this->doorkeeper = $doorkeeper;
    }

    /** @return non-empty-list<string> */
    public function formatLayoutTemplateFiles(): array
    {
        $files = parent::formatLayoutTemplateFiles();
        $files[] = __DIR__ . '/templates/@layout.latte';

        return $files;
    }

    /**
     * The gate, enforced.
     *
     * Nette calls this for the presenter class before startup() and again for
     * every action*(), render*() and handle*() it goes on to call, so one
     * override covers a page, its signals and the forms posted to it. Where it
     * runs is also the trap: it runs *before* startup(), which is where being
     * sent to the sign-in page used to live, so a gate that asked what
     * somebody may do before asking who they are would answer 403 to a visitor
     * it should be sending to sign in. Hence the two passes below, in that
     * order - measured, not reasoned: redirecting from here does produce a
     * 302, because Nette\Application\UI\Presenter::run() catches the
     * AbortException that redirect() throws, and no action or render method is
     * reached afterwards.
     *
     * A page with nothing declared about it raises rather than opening. That
     * is checked for the class only: an action carrying no declaration of its
     * own is covered by the class's, which is the ordinary case, while a class
     * carrying none has nothing behind it at all - and, because a submitted
     * form arrives through processSignal() and asks nothing of any method, a
     * class-level declaration is the only thing standing in front of the
     * forms on the page.
     *
     * @param \ReflectionClass<object>|\ReflectionMethod $element
     */
    public function checkRequirements(\ReflectionClass|\ReflectionMethod $element): void
    {
        parent::checkRequirements($element);

        $gates = $this->gatesOn($element);
        if ($gates === [] && $element instanceof \ReflectionClass) {
            throw new \LogicException(sprintf(
                '%s draws pages of the administration and says nothing about who may open them. Write a '
                    . '#[%s(Resource::Something, Privilege::Something)] above the class, and above any action '
                    . 'that needs more than the rest of it; a page that answers to anybody says so with '
                    . '#[%s(because: ...)].',
                static::class,
                Needs::class,
                OpenToEverybody::class,
            ));
        }

        // Identity first. A visitor who has not signed in holds no roles, so
        // every gate below would refuse them - and being refused is not what
        // should happen to somebody who has not been asked to sign in yet.
        foreach ($gates as $gate) {
            if ($gate->requiresIdentity() && !$this->getUser()->isLoggedIn()) {
                $this->redirect(':Core:Admin:Sign:in');
            }
        }

        // Then permission, of all of them: a declaration on an action narrows
        // the one on the class and never widens it.
        foreach ($gates as $gate) {
            if (!$gate->admits($this->doorkeeper)) {
                $this->error('This is not yours to open.', IResponse::S403_Forbidden);
            }
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
        // The mark in the banner is the way back, and where back is depends on
        // which administration this person has: sending the installation's
        // administrator to the overview of a business would be offering them a
        // link they are refused on, which is the same mistake the menu filter
        // exists to stop.
        $template->overviewUrl = $this->link($this->landing->forThisPerson());
        $template->signOutUrl = $this->link(':Core:Admin:Sign:out');
        $template->publicSiteUrl = $this->link(':Core:Front:Home:default');
        $template->signedIn = $this->getUser()->isLoggedIn();
        $template->identityName = $identity instanceof Identity ? $identity->displayName() : '';
        $template->identityEmail = $identity instanceof Identity ? $identity->email() : '';
        $template->menu = $template->signedIn ? $this->navigation() : [];
    }

    /**
     * Where the administration begins for this person, for a page that has to
     * send them there.
     *
     * It is here rather than injected again by the one page that needs it, so
     * that the sign-in page and the banner cannot come to disagree about it -
     * see Trilobit\Core\Presentation\Admin\Landing.
     */
    protected function landing(): string
    {
        return $this->landing->forThisPerson();
    }

    /**
     * The menu, as addresses rather than as presenter names.
     *
     * The router produces them here rather than in the template, so that an
     * entry pointing at a page this build has no route for fails while the page
     * is being prepared instead of rendering a link that leads nowhere.
     *
     * What is walked is Trilobit\Core\Admin\Menu\ReachableMenu and not the
     * register behind it, so an entry leading somewhere this person would be
     * refused is gone before a link is made of it.
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

    /**
     * The signpost for one section: exactly the bar's own entries for
     * $module, resolved into addresses the same way navigation() resolves
     * every entry.
     *
     * A module a presenter draws this for is trusted to know it has entries -
     * see Trilobit\Core\Admin\Menu\ReachableMenu::itemsOf(), which the caller
     * is expected to have checked is not empty before deciding to render a page
     * at all (decision M2 in .ai/plans/10-menu-submenu-a-rozcestniky.md: a
     * section with nothing to show gets no page, not an empty one).
     *
     * It is filtered by the same service the bar is, which is what decision M2
     * means by one data structure and two renderings: a signpost holding one
     * tile the bar does not would look exactly like a signpost.
     *
     * @return list<SignpostLink>
     */
    protected function signpostOf(string $module): array
    {
        $links = [];
        foreach ($this->menu->itemsOf($module) as $item) {
            $links[] = new SignpostLink(
                $item->label,
                // The leading colon makes the destination absolute; without it
                // Nette would resolve it inside Core, the module this presenter
                // lives in.
                $this->link(':' . $item->destination),
                '',
                'admin-signpost-' . strtolower($item->label),
            );
        }

        return $links;
    }

    /**
     * What was declared above $element, read as the interface and never as a
     * list of attribute names.
     *
     * That is what lets a third kind of gate - the one asking
     * Trilobit\Core\Security\Landlords rather than a resource and a privilege -
     * be added without this method, or any declaration already written above a
     * page, changing at all.
     *
     * @param \ReflectionClass<object>|\ReflectionMethod $element
     *
     * @return list<Gate>
     */
    private function gatesOn(\ReflectionClass|\ReflectionMethod $element): array
    {
        $gates = [];
        foreach ($element->getAttributes(Gate::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $gates[] = $attribute->newInstance();
        }

        return $gates;
    }

    /** A menu entry points at an action; the presenter is everything before it. */
    private function presenterOf(MenuItem $item): string
    {
        $separator = strrpos($item->destination, ':');

        return $separator === false ? $item->destination : substr($item->destination, 0, $separator);
    }
}
