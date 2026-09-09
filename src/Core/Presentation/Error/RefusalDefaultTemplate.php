<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Error;

use Trilobit\Core\Presentation\Front\FrontTemplate;

/**
 * What Core:Error:Refusal:default renders with.
 */
final class RefusalDefaultTemplate extends FrontTemplate
{
    public string $headline = '';

    public string $lead = '';

    /** Whether there is a session to end, which is what decides the way out this page offers. */
    public bool $signedIn = false;

    public string $signOutUrl = '';

    public string $signInUrl = '';
}
