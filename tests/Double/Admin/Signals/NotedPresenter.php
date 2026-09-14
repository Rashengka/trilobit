<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Admin\Signals;

use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * A form written correctly, whose handler is called nothing like save():
 * what the rule reads is where the form may be made, never what its handler
 * is named.
 */
#[Needs(Resource::Content, Privilege::View)]
final class NotedPresenter extends SignalDouble
{
    #[Requires(actions: 'edit')]
    protected function createComponentNote(): Form
    {
        $form = $this->note();
        $form->onSuccess[] = $this->jotDown(...);

        return $form;
    }

    private function jotDown(): never
    {
        $this->did('jotted down');
    }
}
