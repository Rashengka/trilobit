<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Admin;

use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;

/**
 * Where a short address leads: a record in the backoffice of the module that
 * owns it.
 *
 * It renders nothing worth looking at - what is under test is that the short
 * address answers 301 and points here with the identifier intact, and a link
 * can only be generated for a presenter that exists.
 */
final class RecordPresenter extends Presenter
{
    public function actionDefault(int $id): void
    {
        $this->sendResponse(new TextResponse('record ' . $id));
    }
}
