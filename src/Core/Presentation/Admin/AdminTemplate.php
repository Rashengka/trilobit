<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

use Nette\Application\IPresenter;
use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\Template;
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

    /** Written onto <html> as data-theme; the administration is themed like everything else. */
    public string $theme = '';

    /**
     * Whatever the enabled modules contributed, already turned into addresses.
     *
     * Core contributes nothing to it. The way back to the overview is the mark
     * in the header, the same way the way back to the front page is the mark
     * in the public header - which is what lets "one entry per enabled module"
     * be something a test can count.
     *
     * @var list<NavigationItem>
     */
    public array $menu = [];

    public string $overviewUrl = '';

    public string $signOutUrl = '';

    public string $publicSiteUrl = '';

    public bool $signedIn = false;

    public string $identityName = '';

    public string $identityEmail = '';
}
