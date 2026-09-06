<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Admin;

use Trilobit\Core\Presentation\Admin\AdminTemplate;

/**
 * What both views of Cms:Admin:Page render with: the list of pages, and the
 * form one page is written in.
 *
 * One class for two views rather than one each, because the two are one job -
 * an editor listing pages is an editor about to open one - and because the
 * presenter hands Latte one class. Whichever view is drawn reads the
 * properties that mean something to it; the others keep their empty defaults.
 */
final class PagesTemplate extends AdminTemplate
{
    public string $headline = '';

    public string $lead = '';

    /** @var list<PageSummary> */
    public array $pages = [];

    public string $addUrl = '';

    public string $listUrl = '';

    /** Empty while a new page is being written; the address it answers at once it has one. */
    public string $address = '';

    /** The address the visitor would use, drawn as a link where there is one. */
    public string $publicUrl = '';

    public bool $isNew = true;

    /** @var list<string> whatever the form refused, in sentences the editor can act on */
    public array $errors = [];
}
