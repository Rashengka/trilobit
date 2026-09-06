<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Component;

/**
 * Every component the application is allowed to be built out of.
 *
 * This is the register decision D5 turns on, and the reason it is a hand-written
 * list rather than a scan of the directory: a scan would describe whatever is
 * there, and the point is to have something for the directory to be compared
 * against. tests/Template/ComponentRegistryTest fails when the two disagree in
 * either direction, and tests/Template/StyleguideShowsEveryComponentTest fails
 * when a registered variant has no specimen on the style guide page. A component
 * without an example is a component nobody can see before using it, which is how
 * a second one that does almost the same thing gets written.
 *
 * A component belongs here when more than one page needs it, or when a theme has
 * to be able to change it. The style guide's own furniture (the .sg-* classes in
 * assets/base.css) is deliberately not in the list: it dresses the page that
 * shows the components and is not part of the vocabulary anything else draws on.
 */
final class ComponentRegistry
{
    /** Under the project root. */
    public const string DIRECTORY = 'src/Core/Presentation/components'; // check-leaks:allow rule=high_entropy reason=a directory path that happens to be long enough to read as an opaque literal

    /** What a component's root class starts with, and what its name is derived from. */
    public const string PREFIX = 'c-';

    /** @var non-empty-list<Component>|null */
    private ?array $components = null;

    /** @return non-empty-list<Component> in the order the style guide shows them */
    public function all(): array
    {
        return $this->components ??= [
            new Component(
                'c-site-header',
                'The band across the top of every page: who this is, and the way back to the start.',
                ['default', 'with a tagline'],
            ),
            new Component(
                'c-nav',
                'The primary navigation. A theme decides whether it reads as a row or as a column.',
                ['default'],
            ),
            new Component(
                'c-site-footer',
                'The closing band, with a slot for whatever a page has to say last.',
                ['default'],
            ),
            new Component(
                'c-page-heading',
                'The title of a page and the sentence under it.',
                ['default', 'without a lead', 'as a section heading'],
            ),
            new Component(
                'c-card',
                'A linked tile: something to look at, a title that is the link, and a sentence about it.',
                ['default', 'without media'],
            ),
            new Component(
                'c-signpost',
                'The way into each part of something, drawn as a grid of linked tiles built out of c-card.',
                ['default'],
            ),
            new Component(
                'c-button',
                'The one thing a page wants you to do, and the quieter things beside it.',
                ['primary', 'quiet', 'without a destination'],
            ),
            new Component(
                'c-badge',
                'A short label attached to something else: a state, a count, a name.',
                ['plain', 'accent'],
            ),
            new Component(
                'c-field',
                'One thing a form asks for: what it is called, and the control that answers it.',
                ['default', 'with a control that is not a line of text'],
            ),
            new Component(
                'c-notice',
                'One sentence a page has to say to whoever is reading it.',
                ['info', 'danger'],
            ),
            new Component(
                'c-marker-list',
                'A handful of short statements, each one marked.',
                ['default'],
            ),
            new Component(
                'c-panel',
                'A boxed area of a page, with a heading and whatever belongs under it.',
                ['default'],
            ),
            new Component(
                'c-prose',
                'Running text made of the elements a browser already knows, given back the rhythm and the '
                . 'list markers the reset takes away everywhere else.',
                ['default'],
            ),
            new Component(
                'c-table',
                'Rows and columns inside the frame that catches their overflow, so that a table too wide for '
                . 'the space it has scrolls and the page around it stays where it was.',
                ['default', 'with a visible caption'],
            ),
            new Component(
                'c-swatch',
                'One design token, shown as the thing it produces next to the name it is asked for by.',
                ['default'],
            ),
        ];
    }

    /** @return non-empty-list<string> */
    public function names(): array
    {
        return array_map(static fn(Component $component): string => $component->name, $this->all());
    }

    public function find(string $name): ?Component
    {
        foreach ($this->all() as $component) {
            if ($component->name === $name) {
                return $component;
            }
        }

        return null;
    }
}
