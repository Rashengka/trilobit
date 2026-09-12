<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\HTMLDocument;
use Latte\Loaders\StringLoader;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Presentation\Component\ComponentRegistry;
use Trilobit\Tests\Boot;

/**
 * One component, drawn on its own by the engine the application draws it with.
 *
 * A page is the wrong thing to ask about a component that more than one page
 * includes: whatever a claim about it found there would be true of that page and
 * of nothing else. So the component is drawn from a line of Latte written the
 * way a caller writes it, which is also what keeps the parameters honest - a
 * call that no longer matches the block fails here, the same way it would fail
 * in the template that makes it.
 *
 * Every file of the component directory is handed to the loader under the name
 * it is imported by, because a component may import another - and an import
 * that resolved here and not in the application, or the other way round, would
 * be a claim about a different arrangement of files than the one that ships.
 */
final class ComponentRendering
{
    private const string CALLER = 'the caller';

    /**
     * @param string $file the component's file, as a page imports it: preference-switcher.latte
     * @param string $call what a caller writes to draw it: {include preferenceSwitcher, preferences: $preferences}
     * @param array<string, mixed> $parameters what the call refers to, by name
     */
    public static function render(string $file, string $call, array $parameters = []): HTMLDocument
    {
        $directory = Bootstrap::rootDirectory() . '/' . ComponentRegistry::DIRECTORY;

        $sources = [self::CALLER => sprintf("{import '%s'}\n%s", $file, $call)];
        foreach (Finder::findFiles('*.latte')->in($directory) as $component) {
            $sources[$component->getFilename()] = FileSystem::read((string) $component);
        }

        $engine = Boot::container()->getByType(LatteFactory::class)->create();
        $engine->setLoader(new StringLoader($sources));

        return HTMLDocument::createFromString(
            '<!DOCTYPE html><html lang="en"><body>' . $engine->renderToString(self::CALLER, $parameters) . '</body></html>',
            LIBXML_NOERROR,
        );
    }
}
