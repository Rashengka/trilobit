<?php

declare(strict_types=1);

namespace Trilobit\Core\Navigation;

use Trilobit\Core\Domain\Navigation\Menu;
use Trilobit\Core\Presentation\Front\Signpost\SignpostList;

/**
 * The way into each enabled module's own part of the site - the same entry
 * points the front page lists, drawn in the navigation.
 *
 * Read from the signposts rather than from a list of its own, so that the
 * front page and the navigation cannot come to disagree about which sections
 * there are; the order among them is the signposts' too.
 */
final readonly class ModuleSections implements NavigationContributor
{
    public const string KEY = 'core.sections';

    public function __construct(
        private SignpostList $signposts,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'The sections of the modules';
    }

    public function weight(): int
    {
        return 200;
    }

    public function entriesOf(string $menu): array
    {
        if ($menu !== Menu::MAIN) {
            return [];
        }

        $entries = [];
        foreach ($this->signposts->items() as $signpost) {
            $entries[] = NavigationEntry::toDestination(strtolower($signpost->label), $signpost->label, $signpost->destination);
        }

        return $entries;
    }
}
