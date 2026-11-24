<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Admin\Signals;

use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * A form that comes from no factory at all: added to the presenter in
 * startup(), which runs on every action and which no declaration about a
 * factory can be written above.
 */
#[Needs(Resource::Content, Privilege::View)]
final class FormMadeInStartupPresenter extends SignalDouble
{
    protected function startup(): void
    {
        parent::startup();

        $form = $this->note();
        $form->onSuccess[] = fn(): never => $this->did('written from startup');
        $this->addComponent($form, 'note');
    }
}
