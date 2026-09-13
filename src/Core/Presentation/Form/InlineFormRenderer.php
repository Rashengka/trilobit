<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Form;

use Latte\Engine;

/**
 * The controls side by side in a row, wrapping where it runs out, with the
 * buttons starting under them. The labels over the controls may be seen or
 * not; not seen, they are still in the page.
 */
final class InlineFormRenderer extends ArrangedFormRenderer
{
    public function __construct(Engine $latte, private readonly bool $labelsShown = true)
    {
        parent::__construct($latte);
    }

    protected function template(): string
    {
        return __DIR__ . '/templates/inline.latte';
    }

    protected function labelsShown(): bool
    {
        return $this->labelsShown;
    }
}
