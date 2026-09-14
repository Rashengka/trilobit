<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Signals;

use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/** A form named for an action the presenter does not have - refused everywhere, so submitted nowhere. */
#[Needs(Resource::Content, Privilege::View)]
final class PlacedWhereNothingIsDrawnPresenter extends AdminPresenter
{
    public function renderDefault(): void {}

    #[Requires(actions: ['default', 'nowhere'])]
    protected function createComponentForm(): Form
    {
        return new Form();
    }
}
