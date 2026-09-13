<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Form;

/**
 * Every label beside its control: the labels in one column, the controls in
 * the next, and the buttons under the controls.
 */
final class HorizontalFormRenderer extends ArrangedFormRenderer
{
    protected function template(): string
    {
        return __DIR__ . '/templates/horizontal.latte';
    }
}
