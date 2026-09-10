<?php

declare(strict_types=1);

namespace Trilobit\Core\Admin\Menu;

/**
 * What Core itself puts on the administration bar: the way into the section
 * that belongs to the installation rather than to any business in it.
 *
 * Core contributed nothing to the bar until this, and the reason it does now is
 * not that the rule changed but that there is now a page of Core's that a
 * person navigates to. The overview is still reached by the mark in the
 * banner and is still not an entry here.
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
