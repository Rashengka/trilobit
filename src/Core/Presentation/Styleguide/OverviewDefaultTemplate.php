<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Styleguide;

use Trilobit\Core\Presentation\Component\Component;
use Trilobit\Core\Presentation\Content\ContentGroup;
use Trilobit\Core\Presentation\Front\FrontTemplate;
use Trilobit\Core\Presentation\Front\Navigation\NavigationItem;

/**
 * What Core:Styleguide:Overview:default renders with.
 */
final class OverviewDefaultTemplate extends FrontTemplate
{
    /** @var array<string, Component> keyed by name, the way the template asks for them */
    public array $components = [];

    /** @var array<string, ContentGroup> keyed by name, the way the template asks for them */
    public array $contentGroups = [];

    /** @var array<string, string> token name => what it is for */
    public array $colourTokens = [];

    /** @var list<string> */
    public array $statements = [];

    /** @var list<string> the columns of the table specimen that has to overflow */
    public array $tableColumns = [];

    /** @var array<string, list<int>> row label => one figure per column */
    public array $tableRows = [];

    /** @var list<NavigationItem> */
    public array $sampleNavigation = [];
}
