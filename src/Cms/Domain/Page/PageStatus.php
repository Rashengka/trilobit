<?php

declare(strict_types=1);

namespace Trilobit\Cms\Domain\Page;

/**
 * Whether a page is something a visitor may see.
 *
 * There are two states and no third, because the question a request asks is
 * yes or no: the page is drawn, or the address answers 404. A date somebody
 * has to compare against the clock would make that question depend on when it
 * is asked, and a page that appears while nobody is looking is a page nobody
 * reviewed. Scheduling is therefore deliberately absent - **exit condition:**
 * the first time somebody has to publish something at a time they will not be
 * awake for.
 *
 * The state is stored as its own value rather than derived from
 * Trilobit\Cms\Domain\Page\Page::publishedAt() being filled in. A page that
 * has been live and was taken down again keeps the date it went live, and
 * deriving the state would make taking it down mean forgetting that.
 */
enum PageStatus: string
{
    /** Written, not shown. The address is claimed all the same; see Page. */
    case Draft = 'draft';

    case Published = 'published';
}
