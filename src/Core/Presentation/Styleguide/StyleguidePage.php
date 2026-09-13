<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Styleguide;

/**
 * One page of the style guide: where it answers, what it is called, and what
 * it shows.
 *
 * Its address, the file that draws it and the parameters its route carries
 * all follow from the group and the slug by a rule rather than by fields of
 * their own - the same way Trilobit\Core\Presentation\Component\Component
 * derives its block and its file from its name - so that there is nothing to
 * keep in step by hand. See StyleguidePages for the list these belong to.
 */
final readonly class StyleguidePage
{
    /**
     * @param string $group the name of the group it is listed under, and the
     *     first half of its address
     * @param string $slug the second half of its address
     * @param list<string> $components the registered components whose specimens
     *     this page carries; each has to be shown on it, and on no other page
     * @param list<string> $contentGroups the registered groups of native
     *     elements it carries, on the same terms
     * @param list<string> $formElements the registered groups of form controls
     *     it carries, on the same terms
     * @param ?string $width the content width this page insists on, or null
     *     where it is drawn at whatever the reader chose - see
     *     Trilobit\Core\Presentation\Front\FrontPresenter::overruleContentWidth()
     */
    public function __construct(
        public string $group,
        public string $slug,
        public string $title,
        public string $summary,
        public array $components = [],
        public array $contentGroups = [],
        public ?string $width = null,
        public array $formElements = [],
    ) {}

    /** Where it answers, under the style guide's own segment: `components/card`. */
    public function path(): string
    {
        return $this->group . '/' . $this->slug;
    }

    /** The file that draws it, relative to StyleguidePages::directory(). */
    public function file(): string
    {
        return $this->path() . '.latte';
    }

    /** Whether the specimen of this component is on this page. */
    public function shows(string $component): bool
    {
        return in_array($component, $this->components, true);
    }
}
