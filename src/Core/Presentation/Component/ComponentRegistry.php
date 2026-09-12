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
 * when a registered variant has no specimen on any page of the style guide. A
 * component without an example is a component nobody can see before using it,
 * which is how a second one that does almost the same thing gets written.
 *
 * Every entry is also a page of the style guide's Components group, derived
 * from this list by Trilobit\Core\Presentation\Styleguide\StyleguidePages, so a
 * registered component has a page and a place in the guide's menu at once.
 *
 * A component belongs here when more than one page needs it, or when a theme has
 * to be able to change it. The style guide's own furniture (the .sg-* classes in
 * assets/base.css) is deliberately not in the list: it dresses the pages that
 * show the components and is not part of the vocabulary anything else draws on.
 */
final class ComponentRegistry
{
    /** Under the project root. */
    public const string DIRECTORY = 'src/Core/Presentation/components';

    /** What a component's root class starts with, and what its name is derived from. */
    public const string PREFIX = 'c-';

    /** @var non-empty-list<Component>|null */
    private ?array $components = null;

    /** @return non-empty-list<Component> in the order the style guide's menu lists them */
    public function all(): array
    {
        return $this->components ??= [
            // First, because it is the one component the guide uses rather than
            // shows: it is the switch above every page of the guide, and its own
            // page draws it as its specimen instead of a second copy.
            new Component(
                'c-preference-switcher',
                'Every preference this build has, each drawn as the set of answers somebody can switch between.',
                ['default'],
            ),
            new Component(
                'c-site-header',
                'The band across the top of every page: who this is, and the way back to the start.',
                ['default', 'with a tagline', 'with something at the end'],
            ),
            new Component(
                'c-user-menu',
                'Whoever is signed in: a button carrying their name, and the panel it opens with who they are, '
                . 'whatever the page offers them, and the ways out.',
                ['default'],
            ),
            new Component(
                'c-nav',
                'The primary navigation. A theme decides whether it reads as a row or as a column, and whether '
                . 'the entries under an entry unfold in place or open as a block over the page.',
                ['default', 'with entries nested under an entry'],
            ),
            new Component(
                'c-breadcrumb',
                'The way up from the page being drawn: the pages it sits under, in order, ending in the page itself.',
                ['default', 'directly under the start'],
            ),
            new Component(
                'c-pagination',
                'The way through a listing too long for one page: the pages either side of this one, the first '
                . 'and the last, and the steps between them.',
                ['default', 'on the first page', 'on the last page', 'with few pages'],
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
                ['default', 'without media', 'with a footer'],
            ),
            new Component(
                'c-carousel',
                'A strip of slides seen one at a time, turned by hand, by key, or by the buttons and the '
                . 'indicators under it - and never on its own.',
                ['default'],
            ),
            new Component(
                'c-signpost',
                'The way into each part of something, drawn as a grid of linked tiles built out of c-card.',
                ['default'],
            ),
            new Component(
                'c-button',
                'The one thing a page wants you to do, and the quieter things beside it.',
                ['primary', 'quiet', 'danger', 'small', 'without a destination', 'with an icon', 'with only an icon'],
            ),
            new Component(
                'c-button-group',
                'Buttons that belong together, drawn as one piece: a named group of actions, or a set of choices '
                . 'of which one is on, made of real radio buttons.',
                ['default', 'as a set of choices'],
            ),
            new Component(
                'c-icon',
                'A small drawing beside a word, or standing in for one, in the ink of the text around it.',
                ['sign-out', 'chevron-down', 'close'],
            ),
            new Component(
                'c-close',
                'A button that puts away whatever it sits in, drawn as a cross and named for what it does.',
                ['default'],
            ),
            new Component(
                'c-dropdown',
                'A button that opens a short menu of actions or of places to go, drawn over the page and moved '
                . 'through with the arrow keys. Navigation is c-nav, and a panel of who somebody is is c-user-menu.',
                ['default', 'with links', 'lined up with the end'],
            ),
            new Component(
                'c-popover',
                'A button that opens a small panel beside it, with a title and a sentence: what is worth knowing '
                . 'at this place and not worth a page.',
                ['default', 'above'],
            ),
            new Component(
                'c-tooltip',
                'A short description of an element, shown while the pointer rests on it or the focus is on it. '
                . 'It describes the element and never names it.',
                ['default', 'on a button drawn as an icon', 'below'],
            ),
            new Component(
                'c-badge',
                'A short label attached to something else: a state, a count, a name.',
                ['plain', 'accent', 'danger'],
            ),
            new Component(
                'c-field',
                'One thing a form asks for: what it is called, the control that answers it, and what there is '
                . 'to say about the answer.',
                ['default', 'with a control that is not a line of text', 'with a reason and a hint'],
            ),
            new Component(
                'c-combobox',
                'One answer picked from a list: a select the browser draws a control in front of, with a line '
                . 'to search the list by once it is long.',
                [
                    'searching a long list',
                    'a short list, without a line to search it by',
                    'with a reason and a hint',
                    'disabled',
                    'redrawn by Naja',
                ],
            ),
            new Component(
                'c-notice',
                'One sentence a page has to say to whoever is reading it, which the reader may be allowed to put '
                . 'away.',
                ['info', 'danger', 'dismissible', 'with a title'],
            ),
            new Component(
                'c-progress',
                'How far something has got, or how much of something there is: the browser\'s own bar for '
                . 'each, named by a label and saying its value in words.',
                ['under way', 'not known how far', 'a measure', 'a measure past its high bound'],
            ),
            new Component(
                'c-spinner',
                'A wait, said in words: a status naming what is being loaded, and a turning shape beside the '
                . 'words for whoever can see it turn.',
                ['default', 'small', 'with its words shown'],
            ),
            new Component(
                'c-placeholder',
                'The shape of content still on its way, announced once for the whole block.',
                ['lines of text', 'in place of a card'],
            ),
            new Component(
                'c-toast',
                'Short news drawn over the page in a corner of the window, one for every message - what came of '
                . 'something just done, a flash message among them - staying until it is put away.',
                ['info', 'danger', 'several at once', 'from a flash message'],
            ),
            new Component(
                'c-marker-list',
                'A handful of short statements inside running content, each one marked and none of them leading '
                . 'anywhere. Separate entries that lead somewhere, are current or carry a count are c-list-group.',
                ['default'],
            ),
            new Component(
                'c-list-group',
                'A boxed column of separate entries - records, sections, the ways into something - each its own '
                . 'row, one possibly the current one, some carrying a count. Statements inside running text, '
                . 'each merely marked, are c-marker-list.',
                ['default', 'with links', 'with counts'],
            ),
            new Component(
                'c-panel',
                'A boxed area of a page, with a heading and whatever belongs under it.',
                ['default'],
            ),
            new Component(
                'c-modal',
                'A question the page stops to ask over everything else on it, until it is answered or put '
                . 'away: the browser\'s own dialog, shown as a modal.',
                ['default', 'with a form that closes it', 'with a body longer than the window', 'redrawn by Naja'],
            ),
            new Component(
                'c-offcanvas',
                'The same dialog drawn in from an edge of the window - a basket, a set of filters, a menu - and '
                . 'put away by a click on the page beside it.',
                ['at the end', 'at the start', 'at the top', 'at the bottom'],
            ),
            new Component(
                'c-collapse',
                'One thing folded away under its title, opened and closed by the browser\'s own disclosure.',
                ['default', 'open from the start', 'with a heading'],
            ),
            new Component(
                'c-accordion',
                'A column of c-collapse drawn as one block: opened one item at a time when the items share a '
                . 'name, any number at once when they do not.',
                ['one open at a time', 'any number open'],
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
                ['default', 'with a visible caption', 'with column rules', 'framed', 'framed with column rules'],
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
