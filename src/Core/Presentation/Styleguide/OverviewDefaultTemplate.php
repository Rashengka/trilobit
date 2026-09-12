<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Styleguide;

use Trilobit\Core\Presentation\Component\Component;
use Trilobit\Core\Presentation\Component\SignpostLink;
use Trilobit\Core\Presentation\Content\ContentGroup;
use Trilobit\Core\Presentation\Front\FrontTemplate;
use Trilobit\Core\Presentation\Front\Navigation\NavigationItem;

/**
 * What Core:Styleguide:Overview renders with, at the front page of the guide and
 * at every page of it.
 *
 * One class for all of them, because they are pages of one guide rather than
 * separate guides: a page is a selection of specimens drawn out of the same
 * registers with the same invented data, and one class per page would be a
 * dozen classes differing by which of the properties below they leave empty.
 */
final class OverviewDefaultTemplate extends FrontTemplate
{
    /** The page of the guide being drawn, or null on the guide's front page. */
    public ?StyleguidePage $page = null;

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

    /** @var list<SignpostLink> */
    public array $sampleSignposts = [];

    /** The sentence under the title: what the page being drawn is for. */
    public string $lead = '';

    /**
     * Every page of the guide, group by group, with its address resolved -
     * what the menu on every page and the front page of the guide are drawn
     * from.
     *
     * @var list<StyleguideMenuGroup>
     */
    public array $guide = [];

    /** The front page of the guide. */
    public string $guideUrl = '';

    /** Where the page that insists on its own width lives, for the guide to point at. */
    public string $fullWidthUrl = '';
}
