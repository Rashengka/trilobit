<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Styleguide;

use Trilobit\Core\Presentation\Component\SignpostLink;
use Trilobit\Core\Presentation\Front\Navigation\NavigationItem;

/**
 * One group of the style guide's pages, with every address already resolved,
 * in the two shapes the guide draws it in.
 *
 * The menu down the side of every page and the front page of the guide show
 * the same pages, and both are made in one pass over StyleguidePages so that
 * they cannot come to disagree: the items are what the menu draws, the
 * signposts what c-signpost draws on the front page.
 */
final readonly class StyleguideMenuGroup
{
    /**
     * @param list<NavigationItem> $items one per page, the page being drawn
     *     marked as current
     * @param list<SignpostLink> $signposts the same pages, with what each is for
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $summary,
        public array $items,
        public array $signposts,
    ) {}
}
