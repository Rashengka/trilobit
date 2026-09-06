<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front;

use Nette\Application\UI\Presenter;
use Trilobit\Core\Preference\RememberedPreferences;
use Trilobit\Core\Presentation\Design\DesignSystem;
use Trilobit\Core\Presentation\Front\Navigation\NavigationItem;
use Trilobit\Core\Presentation\Front\Signpost\Signpost;
use Trilobit\Core\Presentation\Front\Signpost\SignpostList;

/**
 * The base every public-facing page is built on, whichever module it belongs
 * to.
 *
 * It adds two things: the shared layout, and what the shared layout needs in
 * order to draw itself. The framework looks for a layout beside the presenter
 * and then upwards through the directories above it, which finds Core's own
 * pages and nothing else - a module lives in a different tree entirely.
 * Extending this puts Core's layout at the end of that search, so a module gets
 * the site's chrome by inheriting a class rather than by copying a template or
 * by writing a path into every one of its own.
 *
 * The layout stays last in the list, so a module that does want its own layout
 * simply has one and it wins.
 *
 * The two dependencies arrive through inject methods rather than through the
 * constructor. A constructor argument here would have to be repeated by every
 * presenter in every module, which is how a base class turns into something
 * people work around.
 */
abstract class FrontPresenter extends Presenter
{
    private SignpostList $signposts;

    private DesignSystem $design;

    private RememberedPreferences $remembered;

    public function injectSignposts(SignpostList $signposts): void
    {
        $this->signposts = $signposts;
    }

    public function injectAppearance(DesignSystem $design, RememberedPreferences $remembered): void
    {
        $this->design = $design;
        $this->remembered = $remembered;
    }

    /** @return non-empty-list<string> */
    public function formatLayoutTemplateFiles(): array
    {
        $files = parent::formatLayoutTemplateFiles();
        $files[] = __DIR__ . '/templates/@layout.latte';

        return $files;
    }

    /**
     * What the layout needs, filled in for every page rather than by every page.
     *
     * The preferences end up as attributes on <html>, which is the whole of how
     * a theme is chosen: everything below them in assets/base.css reads tokens,
     * and the tokens are re-declared per theme. Swapping an attribute in the
     * browser swaps the palette and the position of the navigation with no
     * request and no rebuild - see assets/themes/ledger.css.
     *
     * They are read off the device rather than out of the database, which is
     * what lets the first render already be right; the profile of somebody
     * signed in has written itself onto the device at the moment they signed in
     * (decision D8). See Trilobit\Core\Preference\RememberedPreferences.
     */
    protected function beforeRender(): void
    {
        parent::beforeRender();

        $template = $this->getTemplate();
        if (!$template instanceof FrontTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s, because that is what the shared layout is written against.',
                static::class,
                FrontTemplate::class,
            ));
        }

        $template->preferences = $this->remembered->forThisRequest();
        $template->preferenceUrl = $this->link(':Core:Preference:Choice:remember');
        $template->themes = $this->design->themes;
        $template->homeUrl = $this->link(':Core:Front:Home:default');
        $template->navigation = $this->navigation();
    }

    /**
     * The homepage, then whatever the enabled modules contributed, in the order
     * Trilobit\Core\Presentation\Front\Signpost\SignpostList settled on.
     *
     * The addresses are produced by the router, so a signpost pointing at a
     * page this build does not have fails here rather than rendering a link
     * that leads nowhere.
     *
     * @return non-empty-list<NavigationItem>
     */
    private function navigation(): array
    {
        $items = [new NavigationItem(
            'Home',
            $this->link(':Core:Front:Home:default'),
            $this->getName() === 'Core:Front:Home',
            'nav-home',
        )];

        foreach ($this->signposts->items() as $signpost) {
            $items[] = new NavigationItem(
                $signpost->label,
                $this->link(':' . $signpost->destination),
                $this->getName() === $this->presenterOf($signpost),
                'nav-' . strtolower($signpost->label),
            );
        }

        return $items;
    }

    /** A signpost points at an action; the presenter is everything before it. */
    private function presenterOf(Signpost $signpost): string
    {
        $separator = strrpos($signpost->destination, ':');

        return $separator === false ? $signpost->destination : substr($signpost->destination, 0, $separator);
    }
}
