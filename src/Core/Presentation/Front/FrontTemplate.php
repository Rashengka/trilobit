<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front;

use Nette\Application\IPresenter;
use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\Template;
use Trilobit\Core\Preference\Preferences;
use Trilobit\Core\Presentation\Front\Navigation\NavigationItem;

/**
 * What every front template may rely on, and therefore what the layout may
 * rely on.
 *
 * The layout names this class in {templateType}, so a property added here is
 * available in the layout of every page, and a property a single page needs
 * belongs in that page's own template class instead.
 *
 * It extends the framework's abstract template rather than its DefaultTemplate,
 * which is final. The properties below are the ones the template factory fills
 * in when it finds them; it looks them up by name, so declaring them here is
 * what makes them arrive - and declaring them with types is what lets both the
 * analyser and the editor see what a template is working with.
 */
class FrontTemplate extends Template
{
    public IPresenter $presenter;

    public Control $control;

    public string $baseUrl = '';

    public string $basePath = '';

    /** @var list<\stdClass> */
    public array $flashes = [];

    public string $siteName = 'Trilobit';

    public string $siteTagline = 'Modular commerce, contacts and content.';

    public string $pageTitle = '';

    /**
     * The sentence a search engine shows under the title, or empty where the
     * page has nothing to add to what is already on it.
     *
     * Empty draws no element rather than an empty one: a description that says
     * nothing is worse than none at all, because a search engine believes it
     * and shows it.
     */
    public string $metaDescription = '';

    /**
     * What this page is drawn with - the theme, the light or dark mode -
     * written onto <html> as one attribute each.
     *
     * Filled in by FrontPresenter out of whatever this device remembers, over
     * the defaults this build was configured with. It is the whole object
     * rather than one property per preference so that adding a third is a line
     * in Trilobit\Core\Preference\PreferenceCatalogue and nothing here.
     *
     * It has no default because there is no sensible one: a page drawn without
     * it would have no tokens at all, and failing while the page is being
     * prepared is better than rendering something colourless.
     */
    public Preferences $preferences;

    /** Where the switch says that somebody chose something; see PreferenceRoutes. */
    public string $preferenceUrl = '';

    /** @var list<string> every theme this installation has, for the switcher */
    public array $themes = [];

    public string $homeUrl = '';

    /**
     * The permalink of what this page draws, absolute, or empty where the page
     * has none.
     *
     * The shared layout writes it into the head of the document, so that a
     * product reachable through every category it belongs to is one page to a
     * search engine and several to a visitor - decision R12. A page reached by
     * a static route leaves it empty and no element is drawn.
     */
    public string $canonicalUrl = '';

    /** @var list<NavigationItem> */
    public array $navigation = [];
}
