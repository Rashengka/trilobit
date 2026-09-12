<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Styleguide;

use Latte\Extension;

/**
 * Teaches Latte the one tag the style guide draws its specimens with:
 * `{specimen}`, see SpecimenNode.
 *
 * It is registered for every template and not only for the style guide's,
 * because a tag is known to the compiler or it is not - and a tag nobody else
 * writes costs nothing where it is not written.
 */
final class SpecimenExtension extends Extension
{
    public function getTags(): array
    {
        return ['specimen' => SpecimenNode::create(...)];
    }
}
