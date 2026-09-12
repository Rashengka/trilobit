<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Component;

/**
 * The edge of the window a c-offcanvas is drawn against.
 *
 * The two sides are logical rather than left and right: the start is where a
 * line of text begins, so in a language written from the right the start and
 * the end swap sides with the text, and the panel with the basket in it stays
 * where a reader of that language looks for it. The top and the bottom are
 * the same in either direction.
 *
 * An enum rather than a string, because an edge nobody styled is not an error
 * in a browser - it is a panel quietly drawn in the middle of the window.
 */
enum OffcanvasEdge: string
{
    case Start = 'start';
    case End = 'end';
    case Top = 'top';
    case Bottom = 'bottom';
}
