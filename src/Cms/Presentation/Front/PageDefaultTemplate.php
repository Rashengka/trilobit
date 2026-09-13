<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Front;

use Trilobit\Core\Presentation\Front\ContentTemplate;

/**
 * What Cms:Front:Page:default renders with.
 *
 * There is no menu in it. The entries somebody arranged are drawn in the
 * site's navigation, which the layout draws for every page
 * (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3); a copy of them inside the
 * page would be a second list of the same links. A menu inside the content,
 * where an editor chooses to put one, is a control of plan 12.
 */
final class PageDefaultTemplate extends ContentTemplate
{
    public string $heading = '';

    public string $perex = '';

    /** What the editor wrote, as they wrote it. */
    public string $body = '';
}
