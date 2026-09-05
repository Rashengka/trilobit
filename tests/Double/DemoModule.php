<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double;

use Nette\DI\Container;
use Trilobit\Core\Contract\Content\ContentLinkResolver;
use Trilobit\Core\Contract\Content\ContentRef;
use Trilobit\Core\DI\CoreExtension;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Double\Content\DemoCatalogueTypes;
use Trilobit\Tests\Double\Content\DemoContentTypes;
use Trilobit\Tests\Double\Content\DemoLinkResolver;

/**
 * A build with two modules in it that publish content, one of which can be
 * taken away.
 *
 * The register of public addresses, the catch-all that reads it and the port
 * one module links to another through are all finished before the first module
 * has any content to put through them. Rather than invent a content entity
 * inside a real module - which would be a guess about what is later built,
 * committed to src/ and then in the way - a suite adds exactly what a module
 * contributes and nothing else: a presenter mapping, a directory of
 * presenters, a service saying which kinds of content it publishes, and an
 * implementation of the port other modules link to it through.
 *
 * It is the same configuration a real module brings in its own
 * src/<Module>/config/services.neon, written in PHP so that the tags can be
 * named by their constants rather than copied as strings.
 */
final class DemoModule
{
    /** As the mapping below turns a presenter name into a class. */
    public const string PAGE = 'Demo:Front:Page';

    /**
     * The reference a section page has stored: a type and an identifier, never
     * a class and never a foreign key. Its pages try to draw a link to it on
     * every request, so that the build where nothing can resolve it is
     * exercised as often as the one where something can.
     */
    public static function relatedContent(): ContentRef
    {
        return new ContentRef(DemoCatalogueTypes::PRODUCT, '7');
    }

    /**
     * @param bool $catalogue whether this build has the module that owns
     *     products. Without it their addresses are not routed and nothing can
     *     turn a reference to one into a link - the two halves of what
     *     "switched off" has to mean.
     */
    public static function container(bool $catalogue = true): Container
    {
        $services = [
            // The request the visitor made, in place of the one the test
            // runner was started with; see the class.
            'http.request' => ['factory' => StandInHttpRequest::class],
            'demo.contentTypes' => [
                'factory' => DemoContentTypes::class,
                'autowired' => false,
                'tags' => [CoreExtension::TAG_CONTENT_TYPE_PROVIDER],
            ],
        ];

        if ($catalogue) {
            $services['demo.catalogueTypes'] = [
                'factory' => DemoCatalogueTypes::class,
                'autowired' => false,
                'tags' => [CoreExtension::TAG_CONTENT_TYPE_PROVIDER],
            ];
            // Autowired, unlike the two above: Trilobit\Core\DI\PortFallback
            // decides whether to put a null implementation behind a port by
            // asking the container whether anything answers to that type, and
            // a service kept out of autowiring answers to nothing. A real
            // module's port implementation is registered the same way.
            $services['demo.links'] = [
                'factory' => DemoLinkResolver::class,
                'tags' => [CoreExtension::TAG_PORT => ContentLinkResolver::class],
            ];
        }

        return Boot::container(config: [
            'application' => [
                'scanDirs' => [__DIR__],
                'mapping' => ['Demo' => 'Trilobit\Tests\Double\*\*Presenter'],
            ],
            'services' => $services,
        ]);
    }
}
