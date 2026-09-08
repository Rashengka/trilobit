<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Installation;

use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * The other mistake: a page inside the section standing behind a pair rather
 * than behind the person.
 *
 * It would not be a hole - nobody in no business is admitted by a pair either -
 * but it would be a page of this section that a business's administrator could
 * open, and the two scopes would have met.
 */
#[Needs(Resource::Content, Privilege::View)]
final class GatedTheOtherWayPresenter extends AdminPresenter
{
    public function renderDefault(): void {}
}
