<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

use Nette\Application\UI\Template;
use Trilobit\Core\Security\Identity;

/**
 * The page the administration opens on: who is signed in, and what this build
 * is made of.
 *
 * It carries no content belonging to any module, on purpose. A module's own
 * pages are reached from the menu, and the menu is whatever the enabled modules
 * contributed - so this page says the same thing in a build with three modules
 * and in a build with none, which is what makes it the page a build can always
 * be checked against.
 */
final class DashboardPresenter extends AdminPresenter
{
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
        $template->sectionCount = count($template->menu);
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
