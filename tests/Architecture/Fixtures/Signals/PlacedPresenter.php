<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Signals;

use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/** Written the way the rule wants: the form and the signal each name the actions they belong to. */
#[Needs(Resource::Content, Privilege::View)]
final class PlacedPresenter extends AdminPresenter
{
    public function renderDefault(): void {}

    #[Needs(Resource::Content, Privilege::Edit)]
    public function actionEdit(): void {}

    #[Requires(actions: 'default')]
    public function handleRefresh(): void {}

    #[Requires(actions: 'edit')]
    protected function createComponentForm(): Form
    {
        return new Form();
    }
}
