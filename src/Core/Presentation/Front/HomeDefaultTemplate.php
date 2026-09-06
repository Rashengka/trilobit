<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front;

use Trilobit\Core\Presentation\Component\SignpostLink;

/**
 * What Core:Front:Home:default renders with.
 */
final class HomeDefaultTemplate extends FrontTemplate
{
    public string $headline = '';

    public string $tagline = '';

    /** @var list<string> */
    public array $highlights = [];

    /** @var list<SignpostLink> */
    public array $signposts = [];
}
