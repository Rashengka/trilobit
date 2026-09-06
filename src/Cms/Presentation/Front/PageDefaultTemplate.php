<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Front;

use Trilobit\Core\Presentation\Front\ContentTemplate;
use Trilobit\Core\Presentation\Front\Navigation\NavigationItem;

/**
 * What Cms:Front:Page:default renders with.
 *
 * The menu arrives as addresses rather than as the entries somebody arranged,
 * because whether an entry can be drawn at all is decided while the page is
 * being prepared - see Trilobit\Cms\Presentation\Front\PagePresenter. A
 * template handed the entries would have to make that decision itself, and a
 * template cannot: it has no way to ask whether this build has the page an
 * entry names.
 */
final class PageDefaultTemplate extends ContentTemplate
{
    public string $heading = '';

    public string $perex = '';

    /** What the editor wrote, as they wrote it. */
    public string $body = '';

    /** @var list<NavigationItem> the entries of the site menu this build can actually draw */
    public array $menu = [];
}
