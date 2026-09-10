<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

use Nette\Application\IPresenter;
use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\Template;
use Trilobit\Core\Preference\Preferences;
use Trilobit\Core\Presentation\Front\Navigation\NavigationItem;

/**
 * What every administration template may rely on, and therefore what the
 * administration layout may rely on.
 *
 * It is a sibling of Trilobit\Core\Presentation\Front\FrontTemplate rather than
 * a subclass of it. The two chromes have different furniture - one has a
 * signpost into the public site, the other has who is signed in and the way out
 * - and a shared base class would end up carrying the union of both, which is
 * how a template class stops saying anything about the page it belongs to.
 */
class AdminTemplate extends Template
{
    public IPresenter $presenter;

    public Control $control;

    public string $baseUrl = '';

    public string $basePath = '';

    /** @var list<\stdClass> */
    public array $flashes = [];

    public string $siteName = 'Trilobit';

    public string $pageTitle = '';

    /**
     * Written onto <html> as one attribute each; the administration is themed
     * and remembered like everything else, out of the same device and the same
     * profile.
     */
    public Preferences $preferences;

    /** Where the switch says that somebody chose something; see PreferenceRoutes. */
    public string $preferenceUrl = '';

    /**
     * What the person reading this page may open, already turned into
     * addresses.
     *
     * The first of them is the way back to where this person's administration
     * begins, which the mark in the header also leads to and out of the same
     * answer. The rest are the sections: what the enabled modules contributed
     * plus Core's own way into the section belonging to the installation.
     *
     * Everything here, the way back included, has been through
     * Trilobit\Core\Admin\Menu\ReachableMenu - so an entry that would refuse
     * whoever is looking is not here to be clicked. Where the way back would,
     * it is absent and the bar begins with a section; that is the case of a
     * role assembled out of one section, which opens that section and is
     * refused the overview.
     *
     * @var list<NavigationItem>
     */
    public array $menu = [];

    /**
     * Where this person's administration begins, which is what the mark in the
     * banner leads to. It is the overview of a business for whoever
     * administers one and the installation's own section for whoever
     * administers that; see Trilobit\Core\Presentation\Admin\Landing.
     */
    public string $overviewUrl = '';

    /**
     * The application's own address for ending a session, not one under
     * admin/. Signing in belongs to the part of the application somebody is
     * signing into; signing out belongs to the application, because there is
     * one session and ending it is one act. See
     * Trilobit\Core\Presentation\Session\SignOutPresenter.
     */
    public string $signOutUrl = '';

    public string $publicSiteUrl = '';

    public bool $signedIn = false;

    public string $identityName = '';

    public string $identityEmail = '';
}
