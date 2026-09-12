<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

use Nette\Application\UI\Presenter;
use Nette\Application\UI\Template;
use Trilobit\Core\Admin\Menu\MenuItem;
use Trilobit\Core\Admin\Menu\ReachableMenu;
use Trilobit\Core\Preference\RememberedPreferences;
use Trilobit\Core\Presentation\Component\SignpostLink;
use Trilobit\Core\Presentation\Error\RefusalPresenter;
use Trilobit\Core\Presentation\Front\Navigation\NavigationItem;
use Trilobit\Core\Presentation\Session\SignOutPresenter;
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
 *
 * **Being refused is a page, and it is not an error either.** Somebody who is
 * signed in and may not open this page is handed to
 * Trilobit\Core\Presentation\Error\RefusalPresenter rather than raising a
 * Nette\Application\BadRequestException. Raising was the older answer and what
 * was wrong with it is what a visitor met: `catchExceptions: false` in
 * config/common.neon leaves the framework with no error presenter registered
 * while debug mode is on, so it rethrows and the refusal arrives as a stack
 * trace. An account that is refused everywhere then had nowhere left to go -
 * the only way of signing out was drawn in this very layout, on pages it could
 * not reach. Forwarding makes the answer the same however that setting stands,
 * because nothing is thrown for it to decide about; the status is 403 and the
 * address is still the one that was asked for.
 *
 * **One address resolves instead of refusing, and it is the one the
 * administration begins at.** Somebody who administers the installation, typing
 * /admin because it is the only address of the administration anybody knows,
 * was told the page was not theirs to open on an installation that was working
 * perfectly. What answers that is not a second rule about who they are but the
 * answer the application already has - Trilobit\Core\Presentation\Admin\Landing,
 * which the sign-in page and the mark in the banner both ask. See
 * isWhereTheAdministrationBegins() below for the whole of that line, and for why
 * a refusal anywhere else stays a refusal.
 */
abstract class AdminPresenter extends Presenter
{
    /**
     * What the first entry of the bar is called.
     *
     * It names the destination's scope rather than either of the two pages it
     * can lead to, because which one it leads to depends on the person and the
     * word in the bar may not: the overview of a business is called Overview
     * and the installation's own section is called Installation, and an entry
     * calling itself one of those to somebody it takes to the other would be a
     * small lie told on every page.
     */
    private const string WAY_BACK = 'Administration';

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

        // Then, and only on the page the administration begins at, where this
        // person's administration begins. Between the two passes on purpose:
        // after identity, because a visitor who has not signed in has no
        // landing to be sent to and belongs on the sign-in page; before
        // permission, because the whole point is the person the gate below is
        // about to refuse for being in the wrong scope rather than in the
        // wrong job.
        if ($this->isWhereTheAdministrationBegins() && $this->getUser()->isLoggedIn()) {
            $landing = $this->landing->forThisPerson();
            if ($this->presenterIn($landing) !== $this->getName()) {
                $this->redirect($landing);
            }
        }

        // Then permission, of all of them: a declaration on an action narrows
        // the one on the class and never widens it.
        foreach ($gates as $gate) {
            if (!$gate->admits($this->doorkeeper)) {
                $this->forward(RefusalPresenter::DESTINATION);
            }
        }
    }

    /**
     * Whether this page is the address the administration begins at.
     *
     * **The default is no, and that is the whole of the mechanism.** A page
     * added tomorrow refuses whoever may not open it, without its author
     * having to know this method exists; saying yes is a deliberate act, and
     * there is one page in the application that does - the overview, which is
     * what /admin routes to and therefore the address a person types or
     * bookmarks.
     *
     * **What saying yes does not do is turn a refusal into a redirect.** It
     * asks Landing where this person's administration begins and sends them
     * there if it is somewhere else; when the answer is this very page, nothing
     * happens and the gate decides as it does everywhere. So somebody who
     * administers a business and holds nothing in it is still refused here,
     * and somebody who administers the installation is still refused every
     * page of a business's administration - both measured in
     * Trilobit\Tests\Integration\Admin\AdministrationTest. A gate that quietly
     * moved people who tried a page they may not open would never tell anybody
     * they lacked a right, and a gate that never says no is indistinguishable
     * from no gate at all.
     *
     * There is no loop in it for the same reason: the redirect only ever leads
     * to the page Landing named, and that page, asked the same question in the
     * next request, is told it is already where this person belongs.
     */
    protected function isWhereTheAdministrationBegins(): bool
    {
        return false;
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
        // The application's own address rather than one under admin/: one
        // session ended by one act, whoever is ending it. See
        // Trilobit\Core\Presentation\Session\SignOutPresenter.
        $template->signOutUrl = $this->link(SignOutPresenter::DESTINATION);
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
     * The sections on the bar: what the enabled modules contributed and what
     * Core contributed as a section of its own, less whatever would refuse the
     * person reading it.
     *
     * It is what the register holds and therefore what a signpost is drawn
     * from. The way back the bar begins with is not one of them - see
     * navigation() - so a page counting sections counts these and not the
     * drawn bar.
     *
     * @return list<MenuItem>
     */
    protected function sections(): array
    {
        return $this->menu->items();
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
     * **The bar begins with the way back, and the way back is not a section.**
     * The mark in the banner has always led there and still does, but it was
     * not found: somebody deep in a section looked at the bar, which is where
     * the ways from here to there are, and there was no way to the top of it.
     * The address is the same one the mark uses, read from the same
     * Trilobit\Core\Presentation\Admin\Landing, so there is one decision about
     * where the administration begins and not two that agree until one of them
     * is changed.
     *
     * **It is drawn here rather than contributed to the register**, and that is
     * the difference between the way back and a section. A row in the register
     * belongs to whichever module its destination names, and every signpost is
     * drawn from those rows for one module - so a row of Core's leading to the
     * top would appear on the installation section's own signpost as a tile
     * pointing at the page it was drawn on.
     *
     * **Being drawn here does not exempt it from the filter, and assuming it
     * did was wrong.** Landing answers where somebody belongs, and that reads
     * like the same question as what they may open - it is not, and the two
     * came apart once at an ordinary role. Somebody holding `app.administration.content:view` and
     * nothing else was refused the overview while a section did not open the
     * administration it is a section of; the way back was drawn for them all
     * the same, on every page they were allowed to be in, and it led to a
     * refusal. A section opens it now, and that is an agreement between two
     * rules rather than one rule. So the destination goes through
     * Trilobit\Core\Admin\Menu\ReachableMenu::wouldOpen() like everything else
     * the bar offers, and where there is no way back that opens, the bar begins
     * with the first section instead. Nothing is offered that answers 403 -
     * which is what this class's filter says about itself, and it has to be
     * true of the whole bar and not only of the rows in it.
     *
     * @return list<NavigationItem>
     */
    private function navigation(): array
    {
        $items = [];

        $landing = $this->landing->forThisPerson();
        if ($this->menu->wouldOpen($landing)) {
            $items[] = new NavigationItem(
                self::WAY_BACK,
                $this->link($landing),
                $this->getName() === $this->presenterIn($landing),
                'admin-menu-' . strtolower(self::WAY_BACK),
            );
        }

        foreach ($this->sections() as $item) {
            $items[] = new NavigationItem(
                $item->label,
                // The leading colon makes the destination absolute; without it
                // Nette would resolve it inside Core, the module this presenter
                // lives in.
                $this->link(':' . $item->destination),
                $this->getName() === $this->presenterIn($item->destination),
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

    /**
     * A destination points at an action; the presenter is everything before
     * it, and never the leading colon that makes the destination absolute -
     * so that what comes out can be compared with what getName() returns.
     */
    private function presenterIn(string $destination): string
    {
        $separator = strrpos($destination, ':');

        return ltrim($separator === false ? $destination : substr($destination, 0, $separator), ':');
    }
}
