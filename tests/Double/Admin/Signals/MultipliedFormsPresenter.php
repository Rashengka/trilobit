<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Admin\Signals;

use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Form;
use Nette\Application\UI\Multiplier;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * A form per row, made by a Multiplier: the form that receives the signal is
 * two levels below the presenter and is made by a closure, not by a factory of
 * the presenter.
 */
#[Needs(Resource::Content, Privilege::View)]
final class MultipliedFormsPresenter extends SignalDouble
{
    /** @return Multiplier<Form> */
    #[Requires(actions: 'edit')]
    protected function createComponentNotes(): Multiplier
    {
        return new Multiplier(function (string $row): Form {
            $form = $this->note();
            $form->onSuccess[] = fn(): never => $this->did('row ' . $row);

            return $form;
        });
    }
}
