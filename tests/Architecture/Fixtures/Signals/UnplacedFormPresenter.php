<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Signals;

use Nette\Application\UI\Form;
use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/** The shape that went wrong: a form that says nothing about where it may be made. */
#[Needs(Resource::Content, Privilege::View)]
final class UnplacedFormPresenter extends AdminPresenter
{
    public function renderDefault(): void {}

    protected function createComponentForm(): Form
    {
        return new Form();
    }
}
