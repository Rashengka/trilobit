<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Admin\Signals;

use Nette\Application\UI\Form;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/** The shape the content administration had: a form that says nothing about where it may be made. */
#[Needs(Resource::Content, Privilege::View)]
final class UndeclaredFormPresenter extends SignalDouble
{
    protected function createComponentNote(): Form
    {
        $form = $this->note();
        $form->onSuccess[] = fn(): never => $this->did('written by an undeclared form');

        return $form;
    }
}
