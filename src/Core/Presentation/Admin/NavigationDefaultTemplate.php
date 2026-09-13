<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

/** What Core:Admin:Navigation:default renders with. */
final class NavigationDefaultTemplate extends AdminTemplate
{
    public string $headline = '';

    public string $lead = '';

    /** @var list<NavigationRow> */
    public array $rows = [];

    /** @var list<NavigationSourceRow> */
    public array $sources = [];

    /** Whether the person reading may change anything here, or only look. */
    public bool $mayArrange = false;

    /** Whether the business saved an arrangement, so that there is a default to go back to. */
    public bool $isComposed = false;
}
