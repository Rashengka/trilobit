<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Content;

/**
 * Every group of native elements the design system has an opinion about.
 *
 * The style guide is a gate rather than a shop window, and the gate only holds
 * while what is on the page is what the stylesheet does. A component cannot
 * drift far, because it has a file and a block that either renders or does not;
 * an element has neither, so an example of a rule nobody wrote would look
 * exactly like an example of a rule somebody did. That is the failure this
 * register closes: tests/Template/ContentGroupRegistryTest fails when a group
 * names a selector assets/base.css does not carry, and
 * tests/Template/StyleguideShowsEveryContentGroupTest fails when a group has no
 * specimen on the page.
 *
 * The groups are Bootstrap's Content menu read against what this project
 * already had - see .ai/plans/01d-design-system.md, N-B. Tables are the one
 * entry that needed a component rather than a rule, because catching the
 * horizontal overflow takes an element the table can scroll inside.
 */
final class ContentGroupRegistry
{
    /** @var non-empty-list<ContentGroup>|null */
    private ?array $groups = null;

    /** @return non-empty-list<ContentGroup> in the order the style guide shows them */
    public function all(): array
    {
        return $this->groups ??= [
            new ContentGroup(
                'reboot',
                'What a browser draws when nobody wrote a component: a rule, an aside, a quotation, '
                . 'a list of terms. Every one of them is drawn out of the same tokens as everything else, '
                . 'because a browser default cannot follow a theme into its dark mode.',
                ['hr', 'small', 'strong', 'abbr[title]', 'mark', 'blockquote', 'dt', 'dd'],
                ['a rule', 'emphasis and small print', 'a quotation', 'a list of terms'],
            ),
            new ContentGroup(
                'typography',
                'The six heading levels, read off the six steps of the type scale a theme declares. '
                . 'A heading that belongs to a component takes its size from the component and wins; '
                . 'this is what the others fall back to.',
                ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
                ['the heading scale', 'running text'],
            ),
            new ContentGroup(
                'code',
                'Something quoted from a machine rather than said to a person. The monospaced face is set '
                . 'at a size of its own, because how far it has to be brought down to sit level with the '
                . 'body text depends on which two faces a theme picked.',
                ['code', 'kbd', 'samp', 'pre'],
                ['inline', 'a key and sampled output', 'a block wider than its frame'],
            ),
            new ContentGroup(
                'images',
                'A picture is never wider than the space it has and never a shape other than its own. '
                . 'The rule covers svg, picture and video on the same terms, so a diagram and a clip '
                . 'behave the way a photograph does.',
                ['img', 'picture', 'svg', 'video'],
                ['at the size it was drawn', 'in a frame narrower than itself'],
            ),
            new ContentGroup(
                'tables',
                'Rows and columns, and the frame they scroll inside. A table wider than the space it has '
                . 'is not an edge case - a report with a column for every day of a month is wider than any '
                . 'screen - so the overflow is caught one element in and the page never moves.',
                ['table', '.c-table', '.c-table__viewport', '.c-table__actions', '.c-table__number'],
                ['default', 'figures and an actions column', 'wider than its frame'],
            ),
            new ContentGroup(
                'figures',
                'Something to look at and the sentence that says what it is, kept together so that neither '
                . 'is left on its own at the bottom of a column.',
                ['figure', 'figcaption'],
                ['default'],
            ),
        ];
    }

    /** @return non-empty-list<string> */
    public function names(): array
    {
        return array_map(static fn(ContentGroup $group): string => $group->name, $this->all());
    }
}
