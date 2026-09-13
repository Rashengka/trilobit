<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Admin;

use Trilobit\Core\Presentation\Admin\AdminTemplate;

/**
 * What both views of Cms:Admin:Category render with: the categories there
 * are, and the form one is arranged in.
 *
 * It is one class for two views for the same reason
 * Trilobit\Cms\Presentation\Admin\PagesTemplate is.
 */
final class CategoriesTemplate extends AdminTemplate
{
    public string $headline = '';

    public string $lead = '';

    /** @var list<CategorySummary> */
    public array $categories = [];

    public string $addUrl = '';

    public string $listUrl = '';

    /** Where the form asks the register for a last part made of the name. */
    public string $suggestUrl = '';

    public bool $isNew = true;

    /** @var list<string> whatever the form refused, in sentences the editor can act on */
    public array $errors = [];
}
