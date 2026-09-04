<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

/**
 * What Core:Admin:Sign:in renders with.
 */
final class SignInTemplate extends AdminTemplate
{
    public string $headline = '';

    public string $lead = '';

    /**
     * What went wrong, if anything did. The form's own errors are read out
     * here rather than in the template, so that the page is handed strings and
     * not a component to interrogate.
     *
     * @var list<string>
     */
    public array $errors = [];
}
