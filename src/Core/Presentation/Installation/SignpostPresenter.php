<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Installation;

use Nette\Application\UI\Template;
use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Security\AdministersTheInstallation;

/**
 * The signpost of the installation's own section: the way into each part of
 * what is administered above every business rather than inside one.
 *
 * **It is a section of its own and not the overview drawn differently.** The
 * two administrations are two scopes rather than two levels - somebody over
 * the installation has no rights inside a business, not more of them - and a
 * page that changed what it showed according to who was looking would be
 * exactly the silent difference decision B3 was written to avoid. Two
 * addresses, two pages, and which one somebody lands on is a redirect they can
 * see; see Trilobit\Core\Presentation\Admin\Landing.
 *
 * There is no hand-written list of links on it, for the reason the other
 * signpost in this application has none either: it is drawn from the same rows
 * the bar is (decision M2 in .ai/plans/10-menu-submenu-a-rozcestniky.md), so
 * the two cannot come to hold different things. Both readings are filtered in
 * one place - Trilobit\Core\Admin\Menu\ReachableMenu - which is what makes
 * "the bar shows nothing you would be refused" and "the signpost shows nothing
 * you would be refused" one sentence rather than two.
 *
 * **This section asks no permission question of any kind and may not.** The
 * access list has no meaning outside a business and the person reading this
 * page is in none, so such a question would not raise - it would quietly
 * answer no. What holds that down is
 * Trilobit\Tests\Architecture\NoPermissionQuestionInTheInstallationSectionTest,
 * which reads this directory for the two enums the way
 * Trilobit\Tests\Architecture\PermissionQuestions reads the rest of the
 * application.
 */
#[AdministersTheInstallation]
final class SignpostPresenter extends AdminPresenter
{
    /**
     * Everything Core itself contributed to the bar, which is what this
     * section is made of. It is the module segment of a destination and
     * therefore the same key Menu::itemsOf() sorts by; Core is a module to
     * that reading like any other.
     */
    private const string SECTION = 'core';

    public function renderDefault(): void
    {
        $items = $this->signpostOf(self::SECTION);
        if ($items === []) {
            // The same answer the other signpost gives: a section with nothing
            // in it is not a page announcing that it has nothing in it. It
            // cannot happen while Core contributes an entry, and this is what
            // it turns into on the day that stops being true.
            $this->error('This section has nothing to show.');
        }

        $template = $this->getTemplate();
        if (!$template instanceof SignpostTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s.',
                self::class,
                SignpostTemplate::class,
            ));
        }

        $template->pageTitle = 'Installation';
        $template->headline = 'Installation';
        $template->lead = 'What is administered above the businesses rather than inside one of them.';
        $template->items = $items;
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used. Naming the class is what lets the
     * template declare {templateType} and be analysed rather than guessed at.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? SignpostTemplate::class);
    }
}
