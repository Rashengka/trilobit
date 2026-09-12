<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Styleguide;

use Nette\Application\BadRequestException;
use Nette\Application\UI\Template;
use Trilobit\Core\Presentation\Component\Component;
use Trilobit\Core\Presentation\Component\ComponentRegistry;
use Trilobit\Core\Presentation\Component\SignpostLink;
use Trilobit\Core\Presentation\Content\ContentGroup;
use Trilobit\Core\Presentation\Content\ContentGroupRegistry;
use Trilobit\Core\Presentation\Form\FormElementGroup;
use Trilobit\Core\Presentation\Form\FormElementRegistry;
use Trilobit\Core\Presentation\Front\FrontPresenter;
use Trilobit\Core\Presentation\Front\Navigation\NavigationItem;

/**
 * The style guide: every component of the design system, in the theme the page
 * is being rendered in.
 *
 * It is an ordinary page of the application (decision D4). It extends the same
 * base class, renders through the same Latte engine, sits inside the same
 * layout and includes the same component files as the pages a visitor sees, so
 * a component that has stopped working here has stopped working everywhere. A
 * catalogue that rendered its own HTML would show whatever it was told to and
 * would drift away from the application without anybody being able to see it.
 *
 * It answers at the front page of the guide (default) and at every page
 * StyleguidePages lists (page). Every listed page is the same action, told which
 * page it is by its route, and drawn by the file the list names for it.
 *
 * It exists only where trilobit.styleguide is on. Nothing in this class knows
 * that: with the switch off Trilobit\Core\Routing\StyleguideRoutes is never
 * registered, no route reaches here, and the request ends as 404 rather than as
 * a page that admits to existing and refuses.
 *
 * The example content is invented, and has to stay invented.
 */
final class OverviewPresenter extends FrontPresenter
{
    /**
     * The tokens shown as swatches, and what each is for.
     *
     * A hand-written list, and checked against the theme files rather than
     * trusted: tests/Template/ThemesDeclareTheSameTokensTest fails when one of
     * these is missing from a theme, and when the two themes stop declaring the
     * same set.
     *
     * @var array<string, string>
     */
    private const array COLOUR_TOKENS = [
        '--color-canvas' => 'the page itself',
        '--color-surface' => 'anything raised off the page',
        '--color-ink' => 'body text',
        '--color-ink-muted' => 'text that supports other text',
        '--color-line' => 'borders and rules',
        '--color-accent' => 'the action a page is about',
        '--color-danger' => 'something the page had to refuse',
        '--color-nav' => 'behind the navigation',
    ];

    /**
     * The tokens that decide what stays in view while the page scrolls, and
     * what each decides.
     *
     * The layers among them are checked against the theme files rather than
     * trusted: tests/Template/StyleguideFoundationsTest fails when a theme
     * declares a --layout-z-* token the guide does not name.
     *
     * @var array<string, string>
     */
    private const array CHROME_TOKENS = [
        '--layout-banner-position' => 'whether the banner stays in view while the page scrolls (sticky) '
            . 'or scrolls away with it (static)',
        '--layout-nav-position' => 'the same switch for the navigation, set apart from the banner',
        '--layout-nav-inset' => 'how far down the window a held navigation stays: at the top beside the banner, '
            . 'or where the held banner ends when it is under it',
        '--layout-banner-reach' => 'how far down the window the held banner reaches, which a navigation held '
            . 'under it is held at; measured by the page, not declared by a theme',
        '--layout-banner-padding-block' => 'how much room there is above and below what is in the banner',
        '--layout-banner-brand-size' => 'the size the name of the site is set at in the banner',
        '--layout-nav-entry-scale' => 'the size of the navigation\'s own entries, as a share of the size they '
            . 'would otherwise have',
        '--layout-nav-entry-padding-block' => 'the room above and below each of the navigation\'s own entries',
        '--layout-nav-entry-padding-inline' => 'the room either side of each of the navigation\'s own entries',
        '--layout-chrome-motion' => 'how long the bands take to change size; nothing is animated for '
            . 'somebody who asked for reduced motion',
        '--layout-z-chrome' => 'the layer whatever stays in view is drawn in, above the content that '
            . 'scrolls under it',
        '--layout-z-menu' => 'the layer the entries a navigation opens as a block over the page are drawn '
            . 'in, above what stays in view - and the list a combobox opens',
        '--layout-nav-overflow' => 'whether the band the navigation is held in scrolls a menu longer than the '
            . 'window (auto) or lets a block it opens hang out of it (visible)',
        '--layout-chrome-offset' => 'how much of the top of the window the held bands cover, which a jump '
            . 'to a heading keeps clear; measured by the page, not declared by a theme',
    ];

    /**
     * The rows of the table specimen that has to overflow, and the months they
     * are labelled with.
     *
     * @var list<string>
     */
    private const array SAMPLE_TABLE_ROW_LABELS = ['January', 'February', 'March'];

    /** How many columns that specimen has: one for each day of a long month. */
    private const int DAYS_IN_A_LONG_MONTH = 31;

    /** @var list<string> */
    private const array SAMPLE_STATEMENTS = [
        'Every value on this page is read out of a token.',
        'No component writes a colour or a length into its markup.',
        'Switching the theme is one attribute on the html element.',
    ];

    public function __construct(
        private readonly ComponentRegistry $components,
        private readonly ContentGroupRegistry $contentGroups,
        private readonly FormElementRegistry $formElements,
        private readonly StyleguidePages $pages,
    ) {
        parent::__construct();
    }

    /**
     * The one snippet of the guide, drawn again: the specimen of c-combobox
     * that Naja redraws, which is how the guide shows - and
     * tests/e2e/combobox.spec.ts measures - a combobox surviving its select
     * being replaced. Asked for by the button beside it, through Naja; a
     * request that is not Naja's draws the whole page as usual.
     */
    public function handleRedrawSpecimen(): void
    {
        $this->redrawControl('redrawnSpecimen');
    }

    public function renderDefault(): void
    {
        $template = $this->styleguideTemplate();

        $template->pageTitle = 'Style guide';
        $template->lead = 'Every component this application is built out of, rendered by the application itself.';
        $this->fillIn($template, null);
    }

    /**
     * One page of the guide, the one the route named.
     *
     * A page the list does not have is refused as not found, although no route
     * should ever bring one here: a link built inside the application reaches
     * this action without passing the router, and there it is the only check.
     *
     * **The width belongs to the page.** The one page of the list that names a
     * width of its own is drawn at it whatever the reader chose, and it is the
     * same action of the same class as every page that is not - one class
     * answers at several addresses, and they need not be drawn alike. See
     * Trilobit\Core\Presentation\Front\FrontPresenter::overruleContentWidth().
     */
    public function renderPage(string $group, string $page): void
    {
        $shown = $this->pages->find($group, $page);
        if (!$shown instanceof StyleguidePage) {
            throw new BadRequestException(sprintf('The style guide has no page %s/%s.', $group, $page));
        }

        $template = $this->styleguideTemplate();
        $template->setFile(StyleguidePages::directory() . '/' . $shown->file());

        $template->pageTitle = $shown->title;
        $template->lead = $shown->summary;
        $template->page = $shown;
        $this->fillIn($template, $shown);

        if ($shown->width !== null) {
            $this->overruleContentWidth($shown->width);
        }
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used. Naming the class is what lets the
     * template declare {templateType} and be analysed rather than guessed at.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? OverviewDefaultTemplate::class);
    }

    private function styleguideTemplate(): OverviewDefaultTemplate
    {
        $template = $this->getTemplate();
        if (!$template instanceof OverviewDefaultTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s.',
                self::class,
                OverviewDefaultTemplate::class,
            ));
        }

        return $template;
    }

    /**
     * What any page of the guide may draw its specimens with.
     *
     * Filled in for every page rather than for the one that uses each piece:
     * all of it is invented and cheap, and a page moving from one group to
     * another then cannot lose the data it was drawn with on the way.
     */
    private function fillIn(OverviewDefaultTemplate $template, ?StyleguidePage $current): void
    {
        $template->guideUrl = $this->link('default');
        $template->guide = $this->guide($current);
        $template->components = $this->byName($this->components);
        $template->contentGroups = $this->groupsByName($this->contentGroups);
        $template->formElements = $this->formElementsByName($this->formElements);
        $template->colourTokens = self::COLOUR_TOKENS;
        $template->chromeTokens = self::CHROME_TOKENS;
        $template->statements = self::SAMPLE_STATEMENTS;
        $template->tableColumns = $this->sampleTableColumns();
        $template->tableRows = $this->sampleTableRows();
        $template->sampleNavigation = $this->sampleNavigation();
        $template->sampleNestedNavigation = $this->sampleNestedNavigation();
        $template->sampleSignposts = $this->sampleSignposts();
    }

    /**
     * Invented entries with entries under an entry: the case a submenu breaks,
     * which is an entry leading somewhere of its own that also holds a level
     * and a level under that. One branch goes a level deeper still, which is
     * where the themes part - one draws it, the other stops at two.
     *
     * Every entry leads somewhere of its own, and somewhere a click can be seen
     * to have reached: a bare "#" would be a parent with no click to lose.
     *
     * @return list<NavigationItem>
     */
    private function sampleNestedNavigation(): array
    {
        return [
            new NavigationItem('Overview', '#overview', true, 'sample-subnav-overview'),
            $this->sampleEntry('Collections', 'collections', $this->sampleEntry('Fossils', 'fossils', $this->sampleEntry('Trilobites', 'trilobites', $this->sampleEntry('Cambrian', 'cambrian'), $this->sampleEntry('Ordovician', 'ordovician')), $this->sampleEntry('Ammonites', 'ammonites')), $this->sampleEntry('Minerals', 'minerals', $this->sampleEntry('Quartz', 'quartz'), $this->sampleEntry('Feldspar', 'feldspar')), $this->sampleEntry('Field notes', 'field-notes')),
            $this->sampleEntry('Loans', 'loans'),
        ];
    }

    /** One invented entry of the nested specimen, leading to a place on this page named after it. */
    private function sampleEntry(string $label, string $slug, NavigationItem ...$children): NavigationItem
    {
        return new NavigationItem($label, '#' . $slug, false, 'sample-subnav-' . $slug, array_values($children));
    }

    /**
     * Every page of the guide, in one pass over the list, as the menu draws it
     * and as the front page does.
     *
     * The addresses come from the router rather than from the list, so a page
     * listed and not routed fails here, while the page is being prepared,
     * rather than drawing a link that leads nowhere.
     *
     * @return list<StyleguideMenuGroup>
     */
    private function guide(?StyleguidePage $current): array
    {
        $guide = [];
        foreach ($this->pages->groups() as $group) {
            $items = [];
            $signposts = [];
            foreach ($group->pages as $page) {
                $href = $this->link('page', ['group' => $page->group, 'page' => $page->slug]);
                $id = $page->group . '-' . $page->slug;

                $items[] = new NavigationItem(
                    $page->title,
                    $href,
                    $current?->path() === $page->path(),
                    'styleguide-menu-' . $id,
                );
                $signposts[] = new SignpostLink($page->title, $href, $page->summary, 'styleguide-page-' . $id);
            }

            $guide[] = new StyleguideMenuGroup($group->name, $group->title, $group->summary, $items, $signposts);
        }

        return $guide;
    }

    /**
     * The register, keyed by the name a specimen section asks for it by, so
     * that a section naming a component nobody registered is a missing key
     * rather than a section that quietly renders without a heading.
     *
     * @return array<string, Component>
     */
    private function byName(ComponentRegistry $registry): array
    {
        $components = [];
        foreach ($registry->all() as $component) {
            $components[$component->name] = $component;
        }

        return $components;
    }

    /**
     * A column for every day of a month, which is the shape
     * .ai/plans/09-chrome-a-sirka-obsahu.md, L4 is written about: a report
     * wider than any screen at any content width. The specimen has to be that
     * shape and not merely a wide one, because a table that happens to fit on
     * the machine somebody is looking at proves nothing about the frame that is
     * supposed to catch it.
     *
     * @return list<string>
     */
    private function sampleTableColumns(): array
    {
        return array_map(strval(...), range(1, self::DAYS_IN_A_LONG_MONTH));
    }

    /**
     * Invented counts, one per day. Derived rather than typed out: ninety-three
     * numbers written by hand would be ninety-three chances to leave one out,
     * and nothing about this specimen depends on which numbers they are.
     *
     * @return array<string, list<int>>
     */
    private function sampleTableRows(): array
    {
        $rows = [];
        foreach (self::SAMPLE_TABLE_ROW_LABELS as $index => $label) {
            $counts = [];
            foreach (range(1, self::DAYS_IN_A_LONG_MONTH) as $day) {
                $counts[] = $day * (3 + $index * 4) % 19;
            }

            $rows[$label] = $counts;
        }

        return $rows;
    }

    /**
     * The groups of native elements, keyed the same way and for the same
     * reason.
     *
     * @return array<string, ContentGroup>
     */
    private function groupsByName(ContentGroupRegistry $registry): array
    {
        $groups = [];
        foreach ($registry->all() as $group) {
            $groups[$group->name] = $group;
        }

        return $groups;
    }

    /**
     * The groups of form controls, keyed the same way and for the same reason.
     *
     * @return array<string, FormElementGroup>
     */
    private function formElementsByName(FormElementRegistry $registry): array
    {
        $groups = [];
        foreach ($registry->all() as $group) {
            $groups[$group->name] = $group;
        }

        return $groups;
    }

    /**
     * Invented entries rather than the real navigation, so that the specimen
     * shows the same three items whichever modules this build is made of - a
     * catalogue whose examples change with the configuration is a catalogue two
     * people cannot talk about.
     *
     * @return list<NavigationItem>
     */
    private function sampleNavigation(): array
    {
        return [
            new NavigationItem('Overview', '#', true, 'sample-nav-overview'),
            new NavigationItem('Specimens', '#', false, 'sample-nav-specimens'),
            new NavigationItem('Tokens', '#', false, 'sample-nav-tokens'),
        ];
    }

    /**
     * Invented entries, in the shape both real sources hand the component:
     * an address already resolved, and a sentence that is sometimes empty -
     * an administration section's signpost has none to give, which is why
     * one specimen omits it rather than every one carrying an invented sentence
     * neither real source would have written for it.
     *
     * @return list<SignpostLink>
     */
    private function sampleSignposts(): array
    {
        return [
            new SignpostLink(
                'Field guide',
                '#',
                'Every named specimen this collection holds, with where each was found.',
                'sample-signpost-field-guide',
            ),
            new SignpostLink('Loans', '#', '', 'sample-signpost-loans'),
        ];
    }
}
