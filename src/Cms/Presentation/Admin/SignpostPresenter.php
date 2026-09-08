<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Admin;

use Nette\Application\UI\Template;
use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * The signpost at /admin/cms: the way into every section this module put on
 * the administration bar, drawn from the same rows the bar itself reads.
 *
 * There is no page-specific list of links here on purpose - see
 * Trilobit\Core\Presentation\Admin\AdminPresenter::signpostOf(), which reads
 * Trilobit\Core\Admin\Menu\Menu::itemsOf('cms'), the exact rows
 * Trilobit\Cms\Admin\CmsMenu contributed. A second, hand-written list next to
 * that one is how a signpost and a bar drift apart; see M2 in
 * .ai/plans/10-menu-submenu-a-rozcestniky.md.
 *
 * A build in which this module has nothing on the bar never reaches here at
 * all: Trilobit\Cms\Routing\CmsRoutes only registers this route while the
 * module is switched on, and while it is, Trilobit\Cms\Admin\CmsMenu always
 * contributes at least one entry. The empty-list branch below is what a
 * section with nothing to show turns into on the day that stops being true -
 * a 404, not a page announcing that it has nothing to say.
 *
 * **The pair it is gated on is the one the sections behind it are about**,
 * rather than the administration as a whole. This page is a way into content
 * and nothing else, so somebody trusted with content and nothing else has to
 * be able to reach it; the other way round holds by itself, because
 * src/Core/Security/permissions.neon makes content a child of the
 * administration and a rule written on the parent answers for the child.
 * **Exit condition:** the day this module's sections are about more than one
 * resource, when a signpost drawn from every entry would be a page listing
 * things some of the people reading it may not open - which is decision B3's
 * question, asked of a signpost instead of the bar.
 */
#[Needs(Resource::Content, Privilege::View)]
final class SignpostPresenter extends AdminPresenter
{
    private const string MODULE = 'cms';

    public function renderDefault(): void
    {
        $items = $this->signpostOf(self::MODULE);
        if ($items === []) {
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

        $template->pageTitle = 'Cms';
        $template->headline = 'Cms';
        $template->lead = 'Every part of this section, in one place.';
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
