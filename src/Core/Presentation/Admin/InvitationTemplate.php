<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

/** What the page a password is set on renders with; see InvitationPresenter. */
final class InvitationTemplate extends AdminTemplate
{
    public string $headline = '';

    public string $lead = '';

    /** The one sentence every link that does not open is refused with, or '' while it opens. */
    public string $refusal = '';
}
