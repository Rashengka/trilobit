<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Installation;

use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Security\AdministersTheInstallation;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * The mistake the rule exists for: a page of the installation's section asking
 * what somebody may do inside a business.
 *
 * It is gated the right way, which is the point - the gate is not what goes
 * wrong. What goes wrong is one line further in, and at run time it would not
 * raise: the person reading this page holds no roles anywhere, so
 * Nette\Security\User::isAllowed() walks an empty set and answers no without
 * asking anybody. A page written this way would look like a decision somebody
 * had made.
 */
#[AdministersTheInstallation]
final class AskingWhatSomebodyMayDoPresenter extends AdminPresenter
{
    public function renderDefault(): void
    {
        if ($this->getUser()->isAllowed(Resource::Content, Privilege::View)) {
            $this->error('unreachable, and that is the whole trouble with it');
        }
    }
}
