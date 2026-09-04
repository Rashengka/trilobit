<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Utils\Finder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Tests\Boot;

/**
 * Every template compiles with the same engine the application renders with.
 *
 * "Compiles" means Latte can parse it and turn it into PHP - a misspelled
 * filter, an unclosed block, a {templateType} naming a class that does not
 * exist. It does not mean the template renders correctly with real data;
 * that is TemplateContractTest's and the render tests' job.
 *
 * All three modules are enabled so that every template in the tree - not just
 * Core's - has something to compile against, and the engine comes from the
 * container rather than from `new Latte\Engine()` so that it carries the same
 * extensions the application registers, {asset} included.
 *
 * This suite is also the reason Trilobit works as a corpus for
 * `intellij-latte` (CLAUDE.md §8): what compiles here is what the plugin has
 * to stay silent about.
 */
#[CoversNothing]
final class AllTemplatesCompileTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        $root = dirname(__DIR__, 2);

        foreach (Finder::findFiles('*.latte')->from($root . '/src') as $file) {
            $path = (string) $file;
            yield substr($path, strlen($root) + 1) => [$path];
        }
    }

    #[DataProvider('templates')]
    public function testItCompiles(string $file): void
    {
        $container = Boot::container(ModuleList::of(
            ['cms' => true, 'crm' => true, 'shop' => true],
            Bootstrap::rootDirectory(),
        ));

        $engine = $container->getByType(LatteFactory::class)->create();

        self::assertStringContainsString(
            '<?php',
            $engine->compile($file),
            sprintf('%s did not compile to a PHP file', $file),
        );
    }
}
