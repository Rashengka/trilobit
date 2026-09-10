<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Gates;

use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/** A page written the way the rule wants: one declaration, above the class, covering the view it draws. */
#[Needs(Resource::Content, Privilege::View)]
final class GatedOnItsClassPresenter extends AdminPresenter
{
    public function renderDefault(): void {}
}
