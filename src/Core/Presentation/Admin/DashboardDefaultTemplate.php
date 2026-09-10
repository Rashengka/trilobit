<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

/**
 * What Core:Admin:Dashboard:default renders with.
 */
final class DashboardDefaultTemplate extends AdminTemplate
{
    public string $headline = '';

    public string $lead = '';

    /** @var list<string> the codes of the roles the signed-in account holds */
    public array $roles = [];

    /** @var list<string> every permission those roles carry */
    public array $permissions = [];

    /**
     * How many of the modules this build is made of put a section on the menu -
     * the modules, counted once each, and not the entries they contributed.
     */
    public int $moduleCount = 0;
}
