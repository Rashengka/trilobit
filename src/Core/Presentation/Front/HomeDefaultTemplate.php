<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front;

use Trilobit\Core\Presentation\Front\Signpost\Signpost;

/**
 * What Core:Front:Home:default renders with.
 */
final class HomeDefaultTemplate extends FrontTemplate
{
    public string $headline = '';

    public string $tagline = '';

    /** @var list<string> */
    public array $highlights = [];

    /** @var list<Signpost> */
    public array $signposts = [];
}
