<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Admin\Signals;

use Nette\Application\UI\Form;
use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * An administration page built only to be posted to, remembering what its
 * forms and signals did.
 *
 * Every subclass is gated the same way - reading on the class, editing on the
 * `edit` action - and the account the test signs in holds both, so that no
 * gate stands in the way of any request and what refuses one is only ever the
 * rule about where a component or a signal belongs.
 *
 * Whatever a form or a signal does ends the request, so that no template is
 * ever drawn and none has to exist.
 */
abstract class SignalDouble extends AdminPresenter
{
    /** @var list<string> */
    public array $done = [];

    #[Needs(Resource::Content, Privilege::Edit)]
    public function actionEdit(): void {}

    protected function note(): Form
    {
        $form = new Form();
        $form->addText('text', 'Text');
        $form->addSubmit('send', 'Save');

        return $form;
    }

    protected function did(string $what): never
    {
        $this->done[] = $what;
        $this->terminate();
    }
}
