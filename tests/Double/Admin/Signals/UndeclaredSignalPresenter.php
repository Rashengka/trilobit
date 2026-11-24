<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Admin\Signals;

use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/** A signal of the presenter itself, saying nothing about where it may be sent. */
#[Needs(Resource::Content, Privilege::View)]
final class UndeclaredSignalPresenter extends SignalDouble
{
    public function handleForget(): never
    {
        $this->did('forgotten');
    }
}
