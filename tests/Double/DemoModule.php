<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double;

use Nette\DI\Container;
use Trilobit\Core\DI\CoreExtension;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Double\Content\DemoContentTypes;

/**
 * A build with something in it that publishes content.
 *
 * The register of public addresses, the catch-all that reads it and the port
 * one module links to another through are all finished before the first module
 * has any content to put through them. Rather than invent a content entity
 * inside a real module - which would be a guess about what is later built,
 * committed to src/ and then in the way - a suite adds the three things a
 * module contributes and nothing else: a presenter mapping, a directory of
 * presenters, and a service saying which kinds of content it publishes.
 *
 * It is the same configuration a real module brings in its own
 * src/<Module>/config/services.neon, written in PHP so that the tags can be
 * named by their constants rather than copied as strings.
 */
final class DemoModule
{
    /** As the mapping below turns a presenter name into a class. */
    public const string PAGE = 'Demo:Front:Page';

    public static function container(): Container
    {
        return Boot::container(config: [
            'application' => [
                'scanDirs' => [__DIR__],
                'mapping' => ['Demo' => 'Trilobit\Tests\Double\*\*Presenter'],
            ],
            'services' => [
                // The request the visitor made, in place of the one the test
                // runner was started with; see the class.
                'http.request' => ['factory' => StandInHttpRequest::class],
                'demo.contentTypes' => [
                    'factory' => DemoContentTypes::class,
                    'autowired' => false,
                    'tags' => [CoreExtension::TAG_CONTENT_TYPE_PROVIDER],
                ],
            ],
        ]);
    }
}
