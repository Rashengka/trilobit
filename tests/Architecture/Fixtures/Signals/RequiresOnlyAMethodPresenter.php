<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Signals;

use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * The right attribute saying the wrong thing: it restricts the HTTP method and
 * names no action, so the form can still be made on every one of them.
 */
#[Needs(Resource::Content, Privilege::View)]
final class RequiresOnlyAMethodPresenter extends AdminPresenter
{
    public function renderDefault(): void {}

    #[Requires(methods: 'POST')]
    protected function createComponentForm(): Form
    {
        return new Form();
    }
}
