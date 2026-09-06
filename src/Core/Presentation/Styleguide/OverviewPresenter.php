<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Styleguide;

use Nette\Application\UI\Template;
use Trilobit\Core\Presentation\Component\Component;
use Trilobit\Core\Presentation\Component\ComponentRegistry;
use Trilobit\Core\Presentation\Content\ContentGroup;
use Trilobit\Core\Presentation\Content\ContentGroupRegistry;
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
    ) {
        parent::__construct();
    }

    public function renderDefault(): void
    {
        $template = $this->getTemplate();
        if (!$template instanceof OverviewDefaultTemplate) {
            throw new \LogicException(sprintf(
                'The template of %s has to be a %s.',
                self::class,
                OverviewDefaultTemplate::class,
            ));
        }

        $template->pageTitle = 'Style guide';
        $template->components = $this->byName($this->components);
        $template->contentGroups = $this->groupsByName($this->contentGroups);
        $template->colourTokens = self::COLOUR_TOKENS;
        $template->statements = self::SAMPLE_STATEMENTS;
        $template->tableColumns = $this->sampleTableColumns();
        $template->tableRows = $this->sampleTableRows();
        $template->sampleNavigation = $this->sampleNavigation();
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
}
