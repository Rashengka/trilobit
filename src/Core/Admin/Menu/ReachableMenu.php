<?php

declare(strict_types=1);

namespace Trilobit\Core\Admin\Menu;

use Nette\Application\InvalidPresenterException;
use Nette\Application\IPresenterFactory;
use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Security\Doorkeeper;
use Trilobit\Core\Security\Gate;

/**
 * The administration menu as the person reading it may use it: the entries of
 * Trilobit\Core\Admin\Menu\Menu, without the ones that would refuse them.
 *
 * An unreachable destination leaves the menu rather than being clicked. That
 * is the framework's own habit - Nette\Application\LinkGenerator asks
 * AccessPolicy::isLinkable() before it will make a link at all - and this
 * application's, in Trilobit\Core\Presentation\Link\Destinations, which drops a
 * stored destination this build cannot draw. Without it the administrator of a
 * business would be offered the way into the installation and the
 * administrator of the installation the way into every business, and both
 * would find out by being refused.
 *
 * **It is one class because the answer has to be one answer.** The bar and a
 * section's signpost are one data structure drawn twice (decision M2 in
 * .ai/plans/10-menu-submenu-a-rozcestniky.md), so a filter written into each
 * drawing would be two places to disagree - and they would disagree quietly,
 * because a signpost holding one entry too many looks exactly like a signpost.
 * Both readings therefore come through here and Menu is what this reads,
 * not what a page reads.
 *
 * **An entry is dropped only by a gate that refuses it, never by there being
 * none.** Two very different things carry no gate: an entry pointing at a page
 * of the public site, which a module may put on the bar and which anybody may
 * open; and an administration page whose author declared nothing, which is a
 * mistake. Hiding the first would be this class answering a question nobody
 * asked it, and hiding the second would bury a mistake that is otherwise loud
 * in two places - Trilobit\Core\Presentation\Admin\AdminPresenter raises where
 * such a page would be drawn, and
 * Trilobit\Tests\Architecture\EveryAdministrationViewIsGatedTest fails the
 * build before that. So the rule is the narrow one: what refuses, goes.
 *
 * Being signed in is not asked about separately. A gate that wants an identity
 * refuses somebody who has none - the roles they hold are empty and the access
 * list is asked about a name it does not know - so the same pass answers both,
 * and the bar is not drawn at all before somebody is signed in.
 */
final readonly class ReachableMenu
{
    public function __construct(
        private Menu $menu,
        /**
         * Which class answers at a destination, asked of the framework rather
         * than worked out from the string. Turning a presenter's name into a
         * class name is configuration (see application.mapping in
         * config/common.neon) and every module brings its own line of it, so a
         * rule of our own here would be a second copy - and this class would
         * have to know how each module names its presenters, which is exactly
         * what nothing here may know.
         */
        private IPresenterFactory $presenters,
        private Doorkeeper $doorkeeper,
    ) {}

    /** @return list<MenuItem> */
    public function items(): array
    {
        return $this->reachableAmong($this->menu->items());
    }

    /** @return list<MenuItem> */
    public function itemsOf(string $module): array
    {
        return $this->reachableAmong($this->menu->itemsOf($module));
    }

    /**
     * Whether the page at a destination would open for the person making this
     * request - the same question this class asks of every row, asked about a
     * destination that is not one.
     *
     * The bar begins with the way back, and the way back is not a row: it is
     * where Trilobit\Core\Presentation\Admin\Landing says this person's
     * administration begins, which is a different question and deliberately
     * answered elsewhere. **Where somebody belongs is not the same claim as
     * what they may open**, and the two come apart at exactly one shape of
     * account: a role assembled out of a section - `content:view` and nothing
     * else - opens every page of that section and is refused the overview,
     * because the pairs in src/Core/Security/permissions.neon inherit from
     * parent to child. Such a person was drawn a way back to a page that
     * refused them, on every page of the section they were allowed to be in.
     *
     * So the answer to "where" stays in one place and this stays the only
     * thing that decides what is offered - which is what the sentence at the
     * top of this class says, and it has to be true of the whole bar rather
     * than of the rows in it.
     */
    public function wouldOpen(string $destination): bool
    {
        $page = $this->pageOf($destination);
        if (!$page instanceof \ReflectionClass) {
            return true;
        }

        return array_all(
            $this->gatesOn($page, $destination),
            fn(Gate $gate): bool => $gate->admits($this->doorkeeper),
        );
    }

    /**
     * @param list<MenuItem> $items
     *
     * @return list<MenuItem>
     */
    private function reachableAmong(array $items): array
    {
        return array_values(array_filter(
            $items,
            fn(MenuItem $item): bool => $this->wouldOpen($item->destination),
        ));
    }

    /**
     * The administration page a destination leads to, or null when it does not
     * lead to one.
     *
     * Null is three answers in one and they are the same answer here: the
     * build has no presenter of that name, the class is not there, or it draws
     * something other than a page of the administration. None of them is a
     * refusal, so none of them takes an entry out of the menu; a destination
     * this build cannot draw is caught where the link is made instead - see
     * Trilobit\Core\Presentation\Admin\AdminPresenter::navigation().
     *
     * @return \ReflectionClass<AdminPresenter>|null
     */
    private function pageOf(string $destination): ?\ReflectionClass
    {
        $separator = strrpos($destination, ':');
        // A variable and not an expression: the framework takes the name by
        // reference, because asking it also settles the canonical spelling of
        // the name that was asked about.
        $presenter = ltrim($separator === false ? $destination : substr($destination, 0, $separator), ':');

        try {
            $class = $this->presenters->getPresenterClass($presenter);
        } catch (InvalidPresenterException) {
            return null;
        }

        if (!class_exists($class) || !is_subclass_of($class, AdminPresenter::class)) {
            return null;
        }

        /** @var \ReflectionClass<AdminPresenter> $page */
        $page = new \ReflectionClass($class);

        return $page;
    }

    /**
     * Everything that has to admit somebody before this destination is drawn:
     * what stands above the class, and what stands above the action itself.
     *
     * Both, and gathered rather than chosen between, because that is how
     * AdminPresenter::checkRequirements() reads them - a declaration on an
     * action narrows the one on the class and never widens it. A filter
     * reading only the class would leave in the entry that leads to the one
     * action somebody may not open.
     *
     * @param \ReflectionClass<AdminPresenter> $page
     *
     * @return list<Gate>
     */
    private function gatesOn(\ReflectionClass $page, string $destination): array
    {
        $gates = $this->declaredOn($page);

        $separator = strrpos($destination, ':');
        $action = $separator === false ? '' : substr($destination, $separator + 1);
        foreach ($action === '' ? [] : ['action', 'render'] as $prefix) {
            $method = $prefix . ucfirst($action);
            if ($page->hasMethod($method)) {
                $gates = [...$gates, ...$this->declaredOn($page->getMethod($method))];
            }
        }

        return $gates;
    }

    /**
     * Read as the interface and never as a list of attribute names, the same
     * way the presenter and the architecture rule read it - so a fourth kind of
     * gate is covered here the day it is written.
     *
     * @param \ReflectionClass<AdminPresenter>|\ReflectionMethod $element
     *
     * @return list<Gate>
     */
    private function declaredOn(\ReflectionClass|\ReflectionMethod $element): array
    {
        $gates = [];
        foreach ($element->getAttributes(Gate::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $gates[] = $attribute->newInstance();
        }

        return $gates;
    }
}
