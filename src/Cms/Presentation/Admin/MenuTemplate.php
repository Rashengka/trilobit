<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Admin;

use Trilobit\Core\Presentation\Admin\AdminTemplate;

/**
 * What both views of Cms:Admin:Menu render with: the entries somebody
 * arranged, and the form one entry is arranged in.
 *
 * It is one class for two views for the same reason
 * Trilobit\Cms\Presentation\Admin\PagesTemplate is.
 */
final class MenuTemplate extends AdminTemplate
{
    public string $headline = '';

    public string $lead = '';

    /** @var list<MenuSummary> */
    public array $entries = [];

    public string $addUrl = '';

    public string $listUrl = '';

    public bool $isNew = true;

    /** @var list<string> whatever the form refused, in sentences the editor can act on */
    public array $errors = [];
}
