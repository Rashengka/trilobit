<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Module;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Module\Module;
use Trilobit\Core\Module\ModuleList;

/**
 * Which modules a build is made of.
 *
 * The list is read once, at the very start of the boot, and everything else -
 * the configuration files that get loaded, the extensions that get compiled in,
 * the manifest the frontend build reads - follows from it. That is why a
 * malformed list is an exception rather than a default: a build that quietly
 * decides for itself which modules it contains is a build nobody can reason
 * about afterwards.
 */
#[CoversClass(ModuleList::class)]
final class ModuleListTest extends TestCase
{
    private const string Root = '/opt/app';

    public function testOnlyTheModulesSwitchedOnAreEnabled(): void
    {
        $list = ModuleList::of(['shop' => true, 'cms' => false, 'crm' => true], self::Root);

        self::assertSame(['cms', 'crm', 'shop'], $list->names());
        self::assertSame(['crm', 'shop'], $list->enabledNames());
        self::assertTrue($list->isEnabled('shop'));
        self::assertFalse($list->isEnabled('cms'));
    }

    public function testAModuleNobodyDeclaredIsNotEnabled(): void
    {
        self::assertFalse(ModuleList::of([], self::Root)->isEnabled('shop'));
    }

    public function testTheEnabledModulesComeBackInAStableOrder(): void
    {
        $list = ModuleList::of(['shop' => true, 'cms' => true, 'crm' => true], self::Root);

        self::assertSame(
            ['cms', 'crm', 'shop'],
            array_map(static fn(Module $module): string => $module->name, $list->enabled()),
        );
    }

    public function testTheDeclaredListIsReadFromTheConfigurationFile(): void
    {
        $file = $this->neon(<<<'NEON'
            parameters:
                trilobit:
                    modules:
                        shop: true
                        cms: false
            NEON);

        try {
            $list = ModuleList::fromNeon($file, self::Root);

            self::assertSame(['cms', 'shop'], $list->names());
            self::assertSame(['shop'], $list->enabledNames());
        } finally {
            FileSystem::delete(dirname($file));
        }
    }

    public function testAMissingConfigurationFileIsRefusedRatherThanTreatedAsNoModules(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('modules.neon');

        ModuleList::fromNeon(self::Root . '/config/modules.neon', self::Root);
    }

    public function testAFileWithoutTheModuleListIsRefused(): void
    {
        $file = $this->neon("parameters:\n    trilobit:\n        something: else\n");

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('parameters.trilobit.modules');

            ModuleList::fromNeon($file, self::Root);
        } finally {
            FileSystem::delete(dirname($file));
        }
    }

    /**
     * A value that is not a boolean is refused rather than coerced. `crm: no`
     * reads as false to a person and as the string "no" - and therefore as
     * true - to anything that coerces, and that is the one mistake in this file
     * that would ship a module nobody meant to ship.
     */
    public function testAValueThatIsNotABooleanIsRefused(): void
    {
        $file = $this->neon("parameters:\n    trilobit:\n        modules:\n            crm: 'no'\n");

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('crm');

            ModuleList::fromNeon($file, self::Root);
        } finally {
            FileSystem::delete(dirname($file));
        }
    }

    private function neon(string $contents): string
    {
        $directory = sys_get_temp_dir() . '/trilobit-modules-' . bin2hex(random_bytes(6));
        $file = $directory . '/modules.neon';
        FileSystem::write($file, $contents);

        return $file;
    }
}
