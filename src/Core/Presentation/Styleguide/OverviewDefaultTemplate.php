<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Styleguide;

use Trilobit\Core\Presentation\Component\Component;
use Trilobit\Core\Presentation\Content\ContentGroup;
use Trilobit\Core\Presentation\Front\FrontTemplate;
use Trilobit\Core\Presentation\Front\Navigation\NavigationItem;

/**
 * What Core:Styleguide:Overview renders with, at either of its two actions.
 *
 * One class for both, because the second page is a page of the style guide
 * rather than a second guide: it shows the one thing the first cannot show about
 * itself, which is a page drawn at a width nobody chose. Splitting it would mean
 * two classes differing by which half of the properties below they leave empty.
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

    /** Where the page that insists on its own width lives, for the guide to point at. */
    public string $fullWidthUrl = '';

    /** And the way back from it. */
    public string $styleguideUrl = '';
}
