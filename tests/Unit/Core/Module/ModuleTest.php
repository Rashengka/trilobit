<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Module;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Module\Module;

/**
 * One module, seen as the handful of paths and class names its name implies.
 *
 * Everything here is derived from the name rather than configured, which is
 * what lets Core work with a module it has never heard of: the configuration
 * says `shop: true` and nothing else, and the file to load and the class to
 * instantiate follow from that one word.
 */
#[CoversClass(Module::class)]
final class ModuleTest extends TestCase
{
    private const string Root = '/opt/app';

    /**
     * The module here is invented rather than one of the real ones, because
     * the claim is about the convention and not about any module honouring it.
     * A test written against a real module would keep passing if the
     * convention were dropped and that one module hard-wired instead.
     */
    public function testTheNameDecidesEveryPathAndClassName(): void
    {
        $module = Module::named('widget', self::Root);

        self::assertSame('widget', $module->name);
        self::assertSame('Widget', $module->label());
        self::assertSame('Trilobit\Widget', $module->namespace());
        self::assertSame('/opt/app/src/Widget', $module->directory());
        self::assertSame('src/Widget', $module->relativeDirectory());
        self::assertSame('/opt/app/src/Widget/config/services.neon', $module->configFile());
        self::assertSame('Trilobit\Widget\DI\WidgetExtension', $module->extensionClass());
    }

    /**
     * A name is a single lower-case word because it becomes a directory, a
     * namespace and a service prefix at once. Rejecting anything else here is
     * cheaper than discovering half way through a compile that the name was a
     * path fragment.
     */
    #[DataProvider('namesThatAreNotAllowed')]
    public function testANameThatWouldNotSurviveBeingAPathIsRefused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Module::named($name, self::Root);
    }

    /** @return iterable<string, array{string}> */
    public static function namesThatAreNotAllowed(): iterable
    {
        yield 'empty' => [''];
        yield 'capitalised' => ['Shop'];
        yield 'with a separator' => ['shop/admin'];
        yield 'walking up the tree' => ['..'];
        yield 'starting with a digit' => ['2shop'];
        yield 'with a space' => ['web shop'];
    }

    public function testAModuleWhoseExtensionClassIsMissingSaysWhichClassItLookedFor(): void
    {
        $module = Module::named('nosuchmodule', self::Root);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Trilobit\Nosuchmodule\DI\NosuchmoduleExtension');

        $module->createExtension();
    }
}
