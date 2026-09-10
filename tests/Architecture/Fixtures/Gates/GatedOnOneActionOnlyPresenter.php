<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Gates;

use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * The mistake that looks like care being taken: one action is declared for and
 * the class is not.
 *
 * Two things are wrong with it and only one of them is visible page by page.
 * The other view has nothing above it at all; and every form on this
 * presenter, whichever view it is drawn on, is submitted through
 * processSignal(), which asks nothing of any method - so the class-level
 * declaration this has none of is the only thing that would have guarded it.
 */
final class GatedOnOneActionOnlyPresenter extends AdminPresenter
{
    #[Needs(Resource::Content, Privilege::Edit)]
    public function actionEdit(): void {}

    public function renderDefault(): void {}
}
