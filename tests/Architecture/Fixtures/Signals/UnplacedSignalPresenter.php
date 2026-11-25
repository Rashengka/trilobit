<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Signals;

use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/** A signal of the presenter itself rather than of a form, saying nothing about where it may be sent. */
#[Needs(Resource::Content, Privilege::View)]
final class UnplacedSignalPresenter extends AdminPresenter
{
    public function renderDefault(): void {}

    public function handleForget(): void {}
}
