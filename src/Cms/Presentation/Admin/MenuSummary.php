<?php

declare(strict_types=1);

namespace Trilobit\Cms\Presentation\Admin;

/**
 * One menu entry as the list in the administration shows it.
 *
 * It says where the entry leads in words rather than as a link, because an
 * entry may name a module that is not in this build - and the administration
 * is the one place such an entry has to stay visible, so that whoever arranged
 * it can see it is there and decide what to do about it. The site itself
 * leaves it out; see Trilobit\Cms\Presentation\Front\PagePresenter.
 */
final readonly class MenuSummary
{
    public function __construct(
        public int $id,
        public string $menu,
        public string $label,
        /** What it leads to, said in words: a page's title, an address, a presenter's name. */
        public string $leadsTo,
        public bool $isVisible,
        /** Whether this build can draw what it leads to; false is a fact to show, not a fault. */
        public bool $isReachable,
        public string $editUrl,
    ) {}
}
