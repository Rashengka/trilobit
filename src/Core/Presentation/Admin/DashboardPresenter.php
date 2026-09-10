<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

use Nette\Application\UI\Template;
use Trilobit\Core\Admin\Menu\MenuItem;
use Trilobit\Core\Security\Identity;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * The page the administration opens on: who is signed in, and what this build
 * is made of.
 *
 * It carries no content belonging to any module, on purpose. A module's own
 * pages are reached from the menu, and the menu is whatever the enabled modules
 * contributed - so this page says the same thing in a build with three modules
 * and in a build with none, which is what makes it the page a build can always
 * be checked against.
 *
 * **Its gate is the administration itself and nothing narrower.** That pair is
 * what src/Core/Security/permissions.neon says administering anything begins
 * with - "opening it at all is the one thing asked of it" - and this page is
 * where opening it lands, both from the menu and from signing in. A pair
 * belonging to one section would be worse in both directions: somebody who may
 * work on that section and nothing else would be the only person able to see
 * the build's own overview, and everybody else would be refused on the page
 * they arrive at the moment they sign in.
 *
 * **It is also the address the administration begins at**, because /admin is
 * what Trilobit\Core\Routing\AdminRoutes points here and /admin is the address
 * a person types. That, and not the gate above, is why somebody who
 * administers the installation is not refused here: they are sent to the
 * section that is theirs, by the same answer that sends them there when they
 * sign in. Which is a claim about this page alone - see
 * Trilobit\Core\Presentation\Admin\AdminPresenter::isWhereTheAdministrationBegins().
 */
#[Needs(Resource::Administration, Privilege::View)]
final class DashboardPresenter extends AdminPresenter
{
    /**
     * The part of the build that is in every build, as a menu entry names it:
     * the first segment of a destination, lower-cased, the same key
     * Trilobit\Core\Admin\Menu\MenuItem::module() answers with.
     */
    private const string ALWAYS_ON = 'core';

    public function renderDefault(): void
    {
        $template = $this->getTemplate();
        if (!$template instanceof DashboardDefaultTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s.',
                self::class,
                DashboardDefaultTemplate::class,
            ));
        }

        $identity = $this->getUser()->getIdentity();

        $template->pageTitle = 'Overview';
        $template->headline = 'Overview';
        $template->lead = 'Everything this installation is made of, and the way into each part of it.';
        $template->roles = $identity instanceof Identity ? $this->strings($identity->getRoles()) : [];
        $template->permissions = $identity instanceof Identity ? $identity->permissions() : [];
        $template->moduleCount = count($this->contributingModules());
    }

    /**
     * This page and nothing else, so that /admin - which is what leads here -
     * takes somebody who administers the installation to their own section
     * instead of refusing them.
     */
    protected function isWhereTheAdministrationBegins(): bool
    {
        return true;
    }

    /**
     * Which switchable parts of the build put a section on the bar, counted
     * once each.
     *
     * Three things this is not, and each of them was the number this page used
     * to print. It is not how many entries the bar holds: one part contributing
     * two of them made four out of three, and a part with an administration
     * worth the name has several. It is not how many entries are drawn either -
     * the first of those is the way back, which nobody contributed. And what is
     * left out is Core's own, because Core cannot be switched off, and the
     * sentence this feeds is about what switching a part on adds.
     *
     * @return list<string>
     */
    private function contributingModules(): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn(MenuItem $item): string => $item->module(), $this->sections()),
            static fn(string $module): bool => $module !== self::ALWAYS_ON,
        )));
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used. Naming the class is what lets the
     * template declare {templateType} and be analysed rather than guessed at.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? DashboardDefaultTemplate::class);
    }

    /**
     * Nette\Security\IIdentity promises an array of roles and not what is in
     * it, so what is not a string is dropped rather than rendered as whatever
     * PHP makes of it.
     *
     * @param array<int|string, mixed> $roles
     *
     * @return list<string>
     */
    private function strings(array $roles): array
    {
        return array_values(array_filter($roles, is_string(...)));
    }
}
