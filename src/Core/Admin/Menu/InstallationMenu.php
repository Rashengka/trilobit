<?php

declare(strict_types=1);

namespace Trilobit\Core\Admin\Menu;

/**
 * What Core itself puts on the administration bar: the way into the section
 * that belongs to the installation rather than to any business in it.
 *
 * Core contributed nothing to the bar until this, and the reason it does now is
 * not that the rule changed but that there is now a page of Core's that a
 * person navigates to. The way back to the top of the administration is still
 * not an entry here: the bar draws it as its first entry and the mark in the
 * banner leads to it, and both ask
 * Trilobit\Core\Presentation\Admin\Landing rather than reading a row. A row
 * would belong to whichever section its destination names and would turn up on
 * that section's own signpost, pointing at the page it was drawn on.
 *
 * **The entry is drawn for one kind of account and not the other**, and
 * neither this class nor the register it is read out of knows that. It is
 * contributed like any other entry and taken out again by
 * Trilobit\Core\Admin\Menu\ReachableMenu, which reads what the page it leads
 * to says about who may open it. An entry that decided for itself would be a
 * second place where the answer lives, and two places to be wrong in.
 */
final class InstallationMenu implements MenuProvider
{
    /** @return iterable<MenuItem> */
    public function provide(): iterable
    {
        yield new MenuItem('Businesses', 'Core:Installation:Businesses:default');
    }
}
