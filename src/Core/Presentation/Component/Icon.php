<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Component;

/**
 * Every icon c-icon can draw, by the name a template asks for it by.
 *
 * The drawings themselves are in src/Core/Presentation/components/icon.latte,
 * because they are markup; what is here is only the list, and it is here rather
 * than in the template because a template cannot refuse anything. A name nobody
 * drew would otherwise be an empty place in the page - which looks exactly like
 * an icon that is there and too faint to see. from() on a name that is not a
 * case throws, so the mistake stops the page instead.
 *
 * tests/Template/IconTest keeps this list and the drawings in step, and holds
 * the list to the variants the style guide shows.
 *
 * Every icon is drawn by hand for this project rather than taken from a set: two
 * shapes are an hour's work, and a library would be a dependency for the sake of
 * them (CLAUDE.md §5).
 */
enum Icon: string
{
    /** Leaving: a doorway, and an arrow on its way out of it. */
    case SignOut = 'sign-out';

    /** Something that opens underneath whatever carries this. */
    case ChevronDown = 'chevron-down';
}
