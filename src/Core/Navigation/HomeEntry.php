<?php

declare(strict_types=1);

namespace Trilobit\Core\Navigation;

use Trilobit\Core\Domain\Navigation\Menu;

/**
 * The way to the front page, which the site's navigation has begun with since
 * before it could be arranged. A contributor of its own, so that a business
 * may move it or leave it out like anything else in the navigation.
 */
final class HomeEntry implements NavigationContributor
{
    public const string KEY = 'core.home';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'The way to the front page';
    }

    public function weight(): int
    {
        return 0;
    }

    public function entriesOf(string $menu): array
    {
        return $menu === Menu::MAIN ? [NavigationEntry::toDestination('home', 'Home', 'Core:Front:Home:default')] : [];
    }
}
