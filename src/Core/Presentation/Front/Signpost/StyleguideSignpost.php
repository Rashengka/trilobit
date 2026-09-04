<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front\Signpost;

/**
 * The way into the style guide, on the homepage and in the navigation.
 *
 * It is contributed the same way a module contributes its own entry point,
 * because it is the same kind of thing: a page this build has and another build
 * does not. The service is registered only while trilobit.styleguide is on, so
 * a production build has no link to a page it also has no route for - rather
 * than a link that leads to a 404.
 */
final class StyleguideSignpost implements SignpostProvider
{
    /** @return iterable<Signpost> */
    public function provide(): iterable
    {
        yield new Signpost(
            'Styleguide',
            'Core:Styleguide:Overview:default',
            'Every component this application is built out of, in every theme it has.',
        );
    }
}
