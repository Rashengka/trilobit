<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Form;

/** Every label over its control, the fields one under another, and the buttons under them. */
final class VerticalFormRenderer extends ArrangedFormRenderer
{
    protected function template(): string
    {
        return __DIR__ . '/templates/vertical.latte';
    }
}
