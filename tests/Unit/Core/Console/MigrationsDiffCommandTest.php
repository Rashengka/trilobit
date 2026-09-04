<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Console\MigrationsDiffCommand;

/**
 * Which tables a generated migration is allowed to touch, worked out from the
 * namespace it is being generated into.
 *
 * Doctrine's own --namespace decides where the file is written and nothing
 * else: asked for a migration in one module's namespace it happily writes
 * every table in the mapping into it, which in a build with several modules
 * means one module's migration creating another one's tables. The build that
 * then switches that module off runs a migration for it anyway.
 *
 * So the namespace picks the filter as well, and the filter is a table prefix.
 * The mapping between the two is a plain string rule and is tested as one.
 */
#[CoversClass(MigrationsDiffCommand::class)]
final class MigrationsDiffCommandTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function namespaces(): iterable
    {
        yield 'core' => ['Trilobit\Core\Migrations', '/^core_/'];
        yield 'a module' => ['Trilobit\Shop\Migrations', '/^shop_/'];
        yield 'a module nobody has written yet' => ['Trilobit\Blog\Migrations', '/^blog_/'];
    }

    #[DataProvider('namespaces')]
    public function testTheNamespaceDecidesWhichTablesTheDiffMaySee(string $namespace, string $expected): void
    {
        self::assertSame($expected, MigrationsDiffCommand::filterFor($namespace));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namespacesThatNameNoModule(): iterable
    {
        yield 'empty' => [''];
        yield 'somebody else\'s' => ['App\Migrations'];
        yield 'not a migrations namespace' => ['Trilobit\Shop\Domain'];
        yield 'no module in it' => ['Trilobit\Migrations'];
        yield 'deeper than the rule' => ['Trilobit\Shop\Migrations\Old'];
    }

    /**
     * A namespace the rule cannot place has to be refused rather than guessed
     * at. Guessing here would mean a diff with no filter, which is the
     * behaviour this command exists to prevent.
     */
    #[DataProvider('namespacesThatNameNoModule')]
    public function testANamespaceThatNamesNoModuleIsRefused(string $namespace): void
    {
        self::assertNull(MigrationsDiffCommand::filterFor($namespace));
    }
}
