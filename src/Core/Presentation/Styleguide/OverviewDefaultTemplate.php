<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Styleguide;

use Trilobit\Core\Presentation\Component\Component;
use Trilobit\Core\Presentation\Front\FrontTemplate;
use Trilobit\Core\Presentation\Front\Navigation\NavigationItem;

/**
 * What Core:Styleguide:Overview:default renders with.
 */
final class OverviewDefaultTemplate extends FrontTemplate
{
    /** @var array<string, Component> keyed by name, the way the template asks for them */
    public array $components = [];

    /** @var array<string, string> token name => what it is for */
    public array $colourTokens = [];

    /** @var list<string> */
    public array $statements = [];

    /** @var list<NavigationItem> */
    public array $sampleNavigation = [];
}
