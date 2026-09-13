<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front;

use Nette\Application\UI\Presenter;
use Trilobit\Core\Domain\Navigation\Menu;
use Trilobit\Core\Navigation\Navigation;
use Trilobit\Core\Navigation\NavigationEntry;
use Trilobit\Core\Preference\PreferenceCatalogue;
use Trilobit\Core\Preference\RememberedPreferences;
use Trilobit\Core\Presentation\Front\Navigation\NavigationItem;
use Trilobit\Core\Presentation\Link\Destinations;

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
 * The dependencies arrive through inject methods rather than through the
 * constructor. A constructor argument here would have to be repeated by every
 * presenter in every module, which is how a base class turns into something
 * people work around.
 */
abstract class FrontPresenter extends Presenter
{
    private Navigation $navigation;

    private Destinations $destinations;

    private RememberedPreferences $remembered;

    public function injectNavigation(Navigation $navigation, Destinations $destinations): void
    {
        $this->navigation = $navigation;
        $this->destinations = $destinations;
    }

    public function injectAppearance(RememberedPreferences $remembered): void
    {
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
        $template->homeUrl = $this->link(':Core:Front:Home:default');
        $template->navigation = $this->navigation();
    }

    /**
     * The flash messages are drawn by the layout as toasts, in a snippet of
     * their own (src/Core/Presentation/components/toast.latte). A page reached
     * by a redirect draws them as part of itself; an answer to Naja draws only
     * the snippets that changed, so this one is said to have changed whenever
     * there is something in it.
     *
     * After the render method rather than before it, because a message may be
     * said there too. And only when there is something to say: an answer to
     * Naja with nothing to say leaves the toasts already on the page alone.
     */
    protected function afterRender(): void
    {
        parent::afterRender();

        $template = $this->getTemplate();
        if ($this->isAjax() && $template instanceof FrontTemplate && $template->flashes !== []) {
            $this->redrawControl('flashes');
        }
    }

    /**
     * Draw this page at a width of its own, whatever the person reading it
     * usually prefers.
     *
     * The ordering is deliberate and it is the way round that leaves the setting
     * in charge: how wide the content runs is a person's choice, and this is the
     * exception a page is allowed to make where it has a hard reason. The
     * documented one is a report with a column for every day of a month, which
     * overflows at any width and is unusable at a narrow one - a page like that
     * cannot wait for somebody to remember to switch
     * (.ai/plans/09-chrome-a-sirka-obsahu.md, L4).
     *
     * **It belongs to the action and not to the presenter.** One class answers
     * at several addresses, and a monthly report and the form that asks which
     * month may well be two of them; calling this from a render method is what
     * makes the width a property of the page that was actually asked for. Hence
     * also the moment: the preferences reach the template in beforeRender(),
     * which the framework calls before the render method, so a call from an
     * action method is too early and says so rather than being ignored.
     *
     * Nothing is written down. The person's setting is untouched and the switch
     * goes on showing it - see Trilobit\Core\Preference\Preferences.
     */
    protected function overruleContentWidth(string $width): void
    {
        $template = $this->getTemplate();
        if (!$template instanceof FrontTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s.',
                static::class,
                FrontTemplate::class,
            ));
        }

        if (!isset($template->preferences)) {
            throw new \LogicException(sprintf(
                '%s asked for a content width before there were any preferences to overrule; '
                . 'that happens in beforeRender(), so call this from a render method rather than from an action.',
                static::class,
            ));
        }

        $template->preferences = $template->preferences->overruledWith(
            PreferenceCatalogue::CONTENT_WIDTH,
            $width,
        );
    }

    /**
     * The site's navigation, as links: what the build contributes to the main
     * menu, in the order and with the contributors the business saved - see
     * Trilobit\Core\Navigation\Navigation (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3).
     *
     * **Which entries this build can draw is decided here, once, for every
     * contributor alike.** An entry naming a page of a module this build does
     * not have is left out, with whatever is under it: asking the framework for
     * the link instead would draw a broken href and a menu that looks as if it
     * works (Trilobit\Core\Presentation\Link\Destinations).
     *
     * **Which entry is current is decided here too**, because a contributor does
     * not know which page is being drawn. An entry naming a presenter is current
     * on any page of that presenter, the way the navigation has always marked
     * a section; an entry naming an address of the register is current at that
     * address; an address written out never is.
     *
     * Every entry's name in the markup is the navigation's own prefix and the
     * key its contributor gave it. Two contributors may give the same key, and
     * a name used twice would make two entries open each other's lists, so the
     * second is numbered.
     *
     * @return list<NavigationItem>
     */
    private function navigation(): array
    {
        $taken = [];

        return $this->itemsOf($this->navigation->arrangementOf(Menu::MAIN)->entries(), $taken);
    }

    /**
     * @param list<NavigationEntry> $entries
     * @param array<string, true> $taken the names already given on this page
     *
     * @return list<NavigationItem>
     */
    private function itemsOf(array $entries, array &$taken): array
    {
        $items = [];
        foreach ($entries as $entry) {
            $href = $this->hrefOf($entry);
            if ($href === null) {
                continue;
            }

            $name = 'nav-' . $entry->key;
            for ($count = 2; isset($taken[$name]); $count++) {
                $name = 'nav-' . $entry->key . '-' . $count;
            }

            $taken[$name] = true;

            $items[] = new NavigationItem(
                $entry->label,
                $href,
                $this->isCurrent($entry),
                $name,
                $this->itemsOf($entry->children, $taken),
            );
        }

        return $items;
    }

    /** Where $entry leads on this build, or null where this build has nothing there. */
    private function hrefOf(NavigationEntry $entry): ?string
    {
        if ($entry->destination !== null) {
            return $this->destinations->drawnByThisBuild($entry->destination)
                // The leading colon makes the destination absolute; without it
                // Nette would resolve it inside the module of the page being drawn.
                ? $this->link(':' . ltrim($entry->destination, ':'))
                : null;
        }

        if ($entry->path !== null) {
            return $this->getHttpRequest()->getUrl()->getBasePath() . ltrim($entry->path, '/');
        }

        return $entry->url;
    }

    private function isCurrent(NavigationEntry $entry): bool
    {
        if ($entry->destination !== null) {
            return $this->getName() === $this->presenterOf($entry->destination);
        }

        if ($entry->path !== null) {
            return trim($entry->path, '/') === trim($this->getHttpRequest()->getUrl()->getPathInfo(), '/');
        }

        return false;
    }

    /** A destination points at an action; the presenter is everything before it. */
    private function presenterOf(string $destination): string
    {
        $destination = ltrim($destination, ':');
        $separator = strrpos($destination, ':');

        return $separator === false ? $destination : substr($destination, 0, $separator);
    }
}
