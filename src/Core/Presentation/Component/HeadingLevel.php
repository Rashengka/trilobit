<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Component;

/**
 * The level a component may draw a heading at, when the caller decides which
 * one - the way c-collapse does, and c-accordion through it.
 *
 * A component drawn inside a page cannot know where in the outline of the page
 * it lies, so the caller says. What the caller may say is written down here
 * rather than trusted, because a template cannot refuse anything: level one is
 * the title of the page (c-page-heading) and there is no level seven, and
 * either one written into a template would be drawn as something - an <h1>
 * competing with the page's title, or an <h7> no browser knows - with nothing
 * to say it went wrong. from() on anything else throws, so the mistake stops
 * the page instead.
 *
 * The level is structure and not appearance: a component's heading carries the
 * component's class, which decides its size, so it looks the same at every
 * level (.ai/plans/01d-design-system.md, the decision of 2026-09-12).
 */
enum HeadingLevel: int
{
    case Two = 2;
    case Three = 3;
    case Four = 4;
    case Five = 5;
    case Six = 6;

    /** The element a heading of this level is. */
    public function tag(): string
    {
        return 'h' . $this->value;
    }
}
