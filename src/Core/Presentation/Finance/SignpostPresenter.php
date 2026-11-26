<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Finance;

use Nette\Application\UI\Template;
use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * The signpost at /admin/finance: the way into every part of a business's
 * money matters - its rates of tax today, and what joins them later.
 *
 * There is no hand-written list of links on it, for the reason the other
 * signposts have none: it is drawn from the rows the bar is drawn from, the
 * ones Trilobit\Core\Admin\Menu\FinanceMenu contributes, and filtered by the
 * same service - so it holds nothing the bar would not offer this person, and
 * nothing that would refuse them.
 *
 * **The gate is the section's own `view`**, which nobody is given by name:
 * any right inside the section opens it (src/Core/Security/permissions.neon),
 * so whoever may open one of its pages may open its signpost, and somebody
 * holding nothing in it is refused rather than shown an empty page.
 */
#[Needs(Resource::Finance, Privilege::View)]
final class SignpostPresenter extends AdminPresenter
{
    /** The destinations of the section's entries begin with this, and no other entry's do. */
    private const string SECTION = 'Core:Finance:';

    public function renderDefault(): void
    {
        $items = $this->signpostUnder(self::SECTION);
        if ($items === []) {
            // A section with nothing to show is not a page announcing so; see
            // the other signposts.
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

        $template->pageTitle = 'Finance';
        $template->headline = 'Finance';
        $template->lead = 'The money matters of this business: what it charges in tax, and later how its documents are numbered.';
        $template->items = $items;
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? SignpostTemplate::class);
    }
}
