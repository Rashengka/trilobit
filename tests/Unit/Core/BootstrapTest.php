<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;

/**
 * Which files a build is assembled from, and how a change to one of them
 * reaches the compiled container.
 *
 * The second half is not decoration. Outside debug mode the framework hands
 * back the cached container without looking at whether the configuration has
 * changed since - right on a server, and on a working copy a way to edit a
 * NEON file and get no effect and no error either. The compiled container is
 * cached by its static parameters, so what the files say is one of them, and
 * these are the two claims that has to keep: the file list is the one the boot
 * loads, and the value changes when any of them does.
 */
#[CoversClass(Bootstrap::class)]
final class BootstrapTest extends TestCase
{
    public function testTheFileListHoldsTheSharedFilesAndOneFilePerEnabledModule(): void
    {
        $root = Bootstrap::rootDirectory();

        self::assertSame(
            [
                $root . '/config/common.neon',
                $root . '/config/services.neon',
                $root . '/src/Cms/config/services.neon',
                $root . '/src/Shop/config/services.neon',
            ],
            self::withoutLocalOverride(Bootstrap::configurationFiles(
                ModuleList::of(['cms' => true, 'crm' => false, 'shop' => true], $root),
            )),
        );
    }

    public function testASwitchedOffModuleContributesNoFile(): void
    {
        $root = Bootstrap::rootDirectory();
        $files = self::withoutLocalOverride(Bootstrap::configurationFiles(
            ModuleList::of(['cms' => false, 'crm' => false, 'shop' => false], $root),
        ));

        self::assertSame([$root . '/config/common.neon', $root . '/config/services.neon'], $files);
    }

    public function testTheHashChangesWhenAFileChanges(): void
    {
        $directory = sys_get_temp_dir() . '/trilobit-bootstrap-' . bin2hex(random_bytes(6));
        $first = $directory . '/one.neon';
        $second = $directory . '/two.neon';

        FileSystem::write($first, "parameters:\n");
        FileSystem::write($second, "services:\n");

        try {
            $before = Bootstrap::configurationHash([$first, $second]);

            self::assertSame($before, Bootstrap::configurationHash([$first, $second]), 'the same files hash the same');

            FileSystem::write($second, "services:\n    # one comment later\n");
            self::assertNotSame($before, Bootstrap::configurationHash([$first, $second]));
        } finally {
            FileSystem::delete($directory);
        }
    }

    public function testTheHashChangesWhenTheSameContentsArriveInADifferentOrder(): void
    {
        $directory = sys_get_temp_dir() . '/trilobit-bootstrap-' . bin2hex(random_bytes(6));
        $first = $directory . '/one.neon';
        $second = $directory . '/two.neon';

        FileSystem::write($first, "parameters:\n");
        FileSystem::write($second, "services:\n");

        try {
            // Order decides which file wins a repeated key, so two builds that
            // load the same files in a different order are two builds.
            self::assertNotSame(
                Bootstrap::configurationHash([$first, $second]),
                Bootstrap::configurationHash([$second, $first]),
            );
        } finally {
            FileSystem::delete($directory);
        }
    }

    /**
     * config/local.neon belongs to whoever is running this and is in
     * .gitignore, so whether it is there says nothing about the code. It is
     * dropped from the comparison rather than asserted either way.
     *
     * @param list<string> $files
     *
     * @return list<string>
     */
    private static function withoutLocalOverride(array $files): array
    {
        $local = Bootstrap::rootDirectory() . '/config/local.neon';

        return array_values(array_filter($files, static fn(string $file): bool => $file !== $local));
    }
}
