<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Console;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Trilobit\Core\Console\MediaVariantsCommand;
use Trilobit\Core\Media\MediaStorage;
use Trilobit\Core\Media\Variant;
use Trilobit\Tests\Pictures;

/**
 * `bin/trilobit app:media-variants`, which makes every variant again from the
 * originals - after the sizes change, or after www/media was lost.
 *
 * One picture it cannot make is reported and fails the run, and does not stop
 * the others: a run that gave up at the first broken original would leave
 * every picture after it without variants, and one that shrugged would end in
 * the same exit code as a clean run.
 */
#[CoversClass(MediaVariantsCommand::class)]
final class MediaVariantsCommandTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trilobit-media-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        FileSystem::delete($this->directory);
    }

    public function testEveryPictureGetsItsVariantsBack(): void
    {
        $storage = $this->storage();
        $paths = [$this->stored($storage, Pictures::jpeg(40, 30)), $this->stored($storage, Pictures::png(40, 30))];
        FileSystem::delete($this->directory . '/public');

        [$status, $output] = $this->runOn($storage);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertStringContainsString('Made the variants of 2 of 2 pictures.', $output);
        foreach ($paths as $path) {
            foreach (Variant::cases() as $variant) {
                self::assertFileExists($this->directory . '/public/' . MediaStorage::variantPath($path, $variant));
            }
        }
    }

    public function testAnOriginalThatCannotBeUsedFailsTheRunAndNotTheOthers(): void
    {
        $storage = $this->storage();
        $broken = $this->stored($storage, Pictures::jpeg(40, 30));
        $good = $this->stored($storage, Pictures::png(40, 30));
        FileSystem::write($this->directory . '/originals/' . $broken, 'not a picture any more');
        FileSystem::delete($this->directory . '/public');

        [$status, $output] = $this->runOn($storage);

        self::assertSame(Command::FAILURE, $status, $output);
        self::assertStringContainsString($broken, $output);
        self::assertStringContainsString('Made the variants of 1 of 2 pictures.', $output);
        self::assertFileExists($this->directory . '/public/' . MediaStorage::variantPath($good, Variant::Thumb));
    }

    public function testWithNothingStoredThereIsNothingToDo(): void
    {
        [$status, $output] = $this->runOn($this->storage());

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertStringContainsString('There are no pictures to make variants of.', $output);
    }

    private function storage(): MediaStorage
    {
        return new MediaStorage($this->directory . '/originals', $this->directory . '/public');
    }

    private function stored(MediaStorage $storage, string $bytes): string
    {
        $upload = $this->directory . '/uploads/' . bin2hex(random_bytes(4));
        FileSystem::write($upload, $bytes);

        return $storage->store($upload)->path;
    }

    /** @return array{int, string} */
    private function runOn(MediaStorage $storage): array
    {
        $command = new MediaVariantsCommand($storage);
        new Application()->addCommand($command);

        $tester = new CommandTester($command);
        $status = $tester->execute([], ['capture_stderr_separately' => true]);

        return [$status, $tester->getDisplay() . $tester->getErrorOutput()];
    }
}
