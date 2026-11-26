<?php

declare(strict_types=1);

namespace Trilobit\Tests;

use Nette\DI\Container;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\Assert;
use Trilobit\Core\Media\MediaStorage;

/**
 * Pictures a test uploads through the application, kept in a directory of the
 * test's own rather than in the checkout's var/media and www/media.
 *
 * The container's storage is replaced before anything has asked for it, so the
 * library the container builds - and every service built on it - writes where
 * the test says. A test that went through the real directories would leave its
 * pictures behind in a working copy somebody is clicking through.
 */
final class MediaDirectories
{
    /**
     * Points the media storage of $container at a new temporary directory and
     * hands the directory back, for the test to look into and delete.
     */
    public static function temporaryFor(Container $container): string
    {
        $directory = sys_get_temp_dir() . '/trilobit-media-' . bin2hex(random_bytes(6));

        $name = $container->findByType(MediaStorage::class)[0] ?? null;
        Assert::assertIsString($name, 'the build has no media storage to replace');
        Assert::assertFalse(
            $container->isCreated($name),
            'the media storage was already in use, so replacing it now would leave two of them',
        );

        $container->removeService($name);
        $container->addService($name, new MediaStorage($directory . '/originals', $directory . '/public'));

        return $directory;
    }

    public static function delete(string $directory): void
    {
        if ($directory !== '') {
            FileSystem::delete($directory);
        }
    }
}
