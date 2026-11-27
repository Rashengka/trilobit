<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Styleguide;

/**
 * The tokens the foundation pages of the style guide show, grouped the way
 * those pages show them, and what each one is for.
 *
 * Hand-written lists, and checked against the theme files rather than
 * trusted. tests/Template/StyleguideFoundationsTest fails when a theme
 * declares a colour these do not list, or a step of a scale no page names;
 * tests/Template/ThemesDeclareTheSameTokensTest fails when one listed here is
 * missing from a theme.
 *
 * A token of a scale comes with the kind of specimen it is shown as - a bar
 * as long as it, a tile with its corner or its shadow, a line of text set in
 * it, a dot moving along it - which is the name of a modifier of sg-token in
 * assets/base.css. The specimen reads the token out of the theme that is on,
 * so switching the theme or the mode redraws every one of them.
 */
final class FoundationTokens
{
    /** @var array<string, array<string, string>> heading => name => what it is for */
    public const array COLOURS = [
        'The page and what is on it' => [
            '--color-canvas' => 'the page itself',
            '--color-canvas-sunken' => 'a well sunk into the page: code, a quoted block',
            '--color-surface' => 'anything raised off the page',
            '--color-surface-hover' => 'a surface under the pointer',
            '--color-surface-raised' => 'a surface over another surface: a menu, a popover',
            '--color-surface-active' => 'a surface under an entry pressed or chosen',
            '--color-backdrop' => 'laid over the page behind an open dialog, thin enough to show it through',
        ],
        'Ink and rules' => [
            '--color-ink' => 'body text',
            '--color-ink-muted' => 'text that supports other text',
            '--color-ink-subtle' => 'text a reader may pass over: a placeholder, a count',
            '--color-line' => 'borders and rules',
            '--color-line-strong' => 'the edge of a control, where a rule would be too faint to find it by',
            '--color-focus' => 'the ring around whatever has the keyboard',
            '--color-highlight' => 'behind marked text',
            '--color-highlight-ink' => 'marked text itself',
        ],
        'Accent and links' => [
            '--color-accent' => 'the action a page is about',
            '--color-accent-hover' => 'that action under the pointer',
            '--color-accent-active' => 'that action while it is pressed',
            '--color-accent-ink' => 'text set on the accent',
            '--color-accent-surface' => 'a quiet wash of the accent, for something chosen or current',
            '--color-accent-surface-hover' => 'that wash under the pointer',
            '--color-accent-text' => 'text set in the accent, on the page or on its wash',
            '--color-link' => 'a link in running text',
            '--color-link-hover' => 'a link under the pointer',
        ],
        'Success' => [
            '--color-success' => 'something that went as it should: the fill of a control or a badge',
            '--color-success-hover' => 'that fill under the pointer',
            '--color-success-on' => 'text set on the fill',
            '--color-success-surface' => 'a quiet wash of the tone, for a notice',
            '--color-success-ink' => 'text set on the wash, and on the page',
            '--color-success-line' => 'the rule around the wash',
        ],
        'Warning' => [
            '--color-warning' => 'something to look at before going on: the fill',
            '--color-warning-hover' => 'that fill under the pointer',
            '--color-warning-on' => 'text set on the fill',
            '--color-warning-surface' => 'a quiet wash of the tone, for a notice',
            '--color-warning-ink' => 'text set on the wash, and on the page',
            '--color-warning-line' => 'the rule around the wash',
        ],
        'Info' => [
            '--color-info' => 'something worth knowing, neither good nor bad: the fill',
            '--color-info-hover' => 'that fill under the pointer',
            '--color-info-on' => 'text set on the fill',
            '--color-info-surface' => 'a quiet wash of the tone, for a notice',
            '--color-info-ink' => 'text set on the wash, and on the page',
            '--color-info-line' => 'the rule around the wash',
        ],
        'Danger' => [
            '--color-danger' => 'something the page had to refuse: the fill, and the rule of a refused field',
            '--color-danger-hover' => 'that fill under the pointer',
            '--color-danger-on' => 'text set on the fill',
            '--color-danger-surface' => 'a quiet wash of the tone, for a notice',
            '--color-danger-ink' => 'text set on the wash, and on the page',
            '--color-danger-line' => 'the rule around the wash',
        ],
        'Banner and navigation' => [
            '--color-banner' => 'behind the banner',
            '--color-banner-ink' => 'text in the banner',
            '--color-nav' => 'behind the navigation',
            '--color-nav-ink' => 'an entry of the navigation',
            '--color-nav-ink-active' => 'the entry of the page being read',
            '--color-nav-marker' => 'the mark beside or under that entry',
            '--color-nav-hover' => 'an entry under the pointer',
            '--color-nav-panel' => 'behind the entries a navigation opens over the page',
            '--color-nav-panel-line' => 'the rule around them',
        ],
        'Highlighted code' => [
            '--color-code-comment' => 'a comment',
            '--color-code-keyword' => 'a keyword, a tag, a selector',
            '--color-code-name' => 'a name: of an attribute, a property, a function, a class',
            '--color-code-string' => 'a string, the value of an attribute',
            '--color-code-literal' => 'a number, a constant, a variable',
        ],
    ];

    /**
     * The tones a page reports with. Each is one family of six colours: the
     * tone, -hover, -on, -surface, -ink and -line.
     *
     * @var list<string>
     */
    public const array TONES = ['success', 'warning', 'info', 'danger'];

    /**
     * The steps of the type scale, each a size (--text-*) and the line height
     * it is set at (--leading-*), and what it is set at.
     *
     * @var array<string, string>
     */
    public const array TYPE_STEPS = [
        'xs' => 'the smallest print: a count beside a label, a note under a figure',
        'sm' => 'what supports the text around it: a hint, a caption, a label of a table',
        'base' => 'body text',
        'lg' => 'a lead paragraph, and the smallest heading',
        'xl' => 'a heading of a section',
        '2xl' => 'a heading of a page part',
        '3xl' => 'the heading of a page',
        '4xl' => 'a figure or a title that is the whole point of the place it stands in',
    ];

    /** @var array<string, array{kind: string, tokens: array<string, string>}> */
    public const array TYPOGRAPHY = [
        'Families' => [
            'kind' => 'family',
            'tokens' => [
                '--font-body' => 'running text, and every control',
                '--font-display' => 'headings',
                '--font-mono' => 'code, and figures that have to line up',
            ],
        ],
        'Code beside text' => [
            'kind' => 'code',
            'tokens' => [
                '--text-code' => 'the size the monospaced face is set at to sit level with the body face',
            ],
        ],
        'Weights' => [
            'kind' => 'weight',
            'tokens' => [
                '--font-weight-normal' => 'running text',
                '--font-weight-medium' => 'a label that should be found before the text around it',
                '--font-weight-semibold' => 'a label that names what follows it',
                '--font-weight-bold' => 'the heaviest a component asks for',
                '--font-weight-display' => 'titles and headings',
                '--font-weight-strong' => 'emphasis inside running text',
            ],
        ],
        'Line heights by name' => [
            'kind' => 'leading',
            'tokens' => [
                '--leading-tight' => 'headings, which are short and large',
                '--leading-snug' => 'a label or a title that may run onto a second line',
                '--leading-normal' => 'running text',
            ],
        ],
        'Tracking' => [
            'kind' => 'tracking',
            'tokens' => [
                '--tracking-tight' => 'large type, which looks loose at its size',
                '--tracking-normal' => 'everything else',
                '--tracking-wide' => 'a short label at a small size, or in capitals',
                '--tracking-display' => 'headings',
            ],
        ],
    ];

    /** @var array<string, array{kind: string, tokens: array<string, string>}> */
    public const array SIZES = [
        'Space' => [
            'kind' => 'bar',
            'tokens' => [
                '--spacing' => 'the unit every utility spacing is a multiple of',
                '--space-3xs' => 'between a thing and its own outline',
                '--space-2xs' => 'between an icon and its word',
                '--space-xs' => 'between the parts of one control',
                '--space-sm' => 'between controls in a row',
                '--space-md' => 'inside a card or a notice',
                '--space-lg' => 'between the blocks of a section',
                '--space-xl' => 'between sections',
                '--space-2xl' => 'between the parts of a page',
            ],
        ],
        'Controls' => [
            'kind' => 'height',
            'tokens' => [
                '--control-size-xs' => 'the smallest control: inside a table row, beside a count',
                '--control-size-sm' => 'a small control: a toolbar, a filter',
                '--control-size-md' => 'every control a size was not chosen for',
                '--control-size-lg' => 'the control a page is about',
                '--control-size-text' => 'a control holding one line of text: the line, its padding and its edge',
                '--control-list-inset' => 'not a choice: the inset Chromium adds inside a native list, taken off its padding',
            ],
        ],
        'Icons in a control' => [
            'kind' => 'icon',
            'tokens' => [
                '--control-icon-xs' => 'the icon in a control of the smallest size',
                '--control-icon-sm' => 'the icon in a small control',
                '--control-icon-md' => 'the icon in a control of the middle size',
                '--control-icon-lg' => 'the icon in the large control',
            ],
        ],
        'Icons' => [
            'kind' => 'icon',
            'tokens' => [
                '--icon-size' => 'an icon beside a word, as tall as the text it stands beside',
                '--icon-size-sm' => 'an icon smaller than the letters beside it',
                '--icon-size-md' => 'the same as an icon beside a word',
                '--icon-size-lg' => 'an icon standing for a whole place on its own',
            ],
        ],
        'Bars and spinners' => [
            'kind' => 'height',
            'tokens' => [
                '--progress-size' => 'the thickness of a progress bar',
                '--spinner-size' => 'a spinner standing for a whole region that is loading',
            ],
        ],
        'Widths' => [
            'kind' => 'bar',
            'tokens' => [
                '--layout-breakpoint-sm' => 'a narrow window ends here',
                '--layout-breakpoint-md' => 'a window has room for a column beside the content from here',
                '--layout-breakpoint-lg' => 'a wide window starts here',
                '--layout-container-xs' => 'a column for a form on its own',
                '--layout-container-sm' => 'a column for the body of a dialog',
                '--layout-container-md' => 'a column for a page of settings',
                '--layout-container-lg' => 'a column for a page of records',
                '--layout-container-xl' => 'the widest column narrower than the content',
                '--layout-aside-size' => 'the column beside the content',
                '--layout-auth-card-width' => 'the card a page holding nothing but a sign-in form is drawn in',
            ],
        ],
    ];

    /** @var array<string, array{kind: string, tokens: array<string, string>}> */
    public const array SHAPE = [
        'Corners' => [
            'kind' => 'radius',
            'tokens' => [
                '--radius-xs' => 'the corner of something small: a checkbox, a tag',
                '--radius-sm' => 'a control, code inside a line',
                '--radius-md' => 'a card, a notice, a menu',
                '--radius-lg' => 'a dialog, a large surface',
                '--radius-xl' => 'a surface that is the whole of a page part',
                '--radius-pill' => 'a badge or a button drawn as a pill',
            ],
        ],
        'Elevation' => [
            'kind' => 'shadow',
            'tokens' => [
                '--shadow-xs' => 'barely off the page: a card at rest',
                '--shadow-sm' => 'a card under the pointer, a control lifted off its row',
                '--shadow-md' => 'a menu or a popover, over what is beside it',
                '--shadow-lg' => 'a dialog, laid over the page',
                '--shadow-raised' => 'what every raised component is drawn with',
            ],
        ],
        'Rules' => [
            'kind' => 'border',
            'tokens' => [
                '--border-hairline' => 'every border and rule',
                '--border-marker' => 'the mark beside or under the current entry',
            ],
        ],
    ];

    /** @var array<string, array{kind: string, tokens: array<string, string>}> */
    public const array MOTION = [
        'Durations' => [
            'kind' => 'transition',
            'tokens' => [
                '--motion-fast' => 'a hover, a change of colour',
                '--motion-base' => 'most changes of state',
                '--motion-unfold' => 'a disclosure opening or closing',
                '--motion-layer' => 'a dialog coming in over the page and going again',
                '--motion-loop' => 'one turn of whatever loops while a page waits',
            ],
        ],
        'Curves' => [
            'kind' => 'easing',
            'tokens' => [
                '--ease-standard' => 'something moving from one place on the page to another',
                '--ease-out' => 'something arriving: it starts quickly and settles',
            ],
        ],
        'Loops' => [
            'kind' => 'animation',
            'tokens' => [
                '--animate-spin' => 'a turn, for a spinner',
                '--animate-ping' => 'a mark calling attention to a point, for something in progress',
                '--animate-pulse' => 'a fade in and out, for a placeholder',
                '--animate-sweep' => 'a run across and back, for a bar that does not know how far it has got',
            ],
        ],
    ];

    /**
     * Every colour token, in one list.
     *
     * @return array<string, string> name => what it is for
     */
    public static function colours(): array
    {
        return array_merge(...array_values(self::COLOURS));
    }
}
