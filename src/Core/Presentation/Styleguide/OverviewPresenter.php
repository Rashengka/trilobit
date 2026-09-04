<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Styleguide;

use Nette\Application\UI\Template;
use Trilobit\Core\Presentation\Component\Component;
use Trilobit\Core\Presentation\Component\ComponentRegistry;
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
        '--color-nav' => 'behind the navigation',
    ];

    /** @var list<string> */
    private const array SAMPLE_STATEMENTS = [
        'Every value on this page is read out of a token.',
        'No component writes a colour or a length into its markup.',
        'Switching the theme is one attribute on the html element.',
    ];

    public function __construct(
        private readonly ComponentRegistry $components,
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
        $template->colourTokens = self::COLOUR_TOKENS;
        $template->statements = self::SAMPLE_STATEMENTS;
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
