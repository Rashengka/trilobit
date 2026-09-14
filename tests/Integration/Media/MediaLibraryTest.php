<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Media;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\Media\MediaFile;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Media\MediaLibrary;
use Trilobit\Core\Media\MediaStorage;
use Trilobit\Core\Media\UploadRefused;
use Trilobit\Core\Tenancy\Tenancy;
use Trilobit\Core\Tenancy\TenancyRefused;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Pictures;
use Trilobit\Tests\Tenants;

/**
 * An upload as the application records it: the files through MediaStorage, and
 * a row of the business it belongs to - both or neither.
 *
 * The directories are the case's own rather than the checkout's var/media and
 * www/media, so a run leaves nothing in the working copy it ran in.
 */
#[CoversNothing]
final class MediaLibraryTest extends TestCase
{
    private string $schema = '';

    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trilobit-media-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        FileSystem::delete($this->directory);

        if ($this->schema !== '') {
            Database::drop($this->schema);
        }
    }

    public function testAnUploadIsRecordedForItsBusinessWithWhatWasReadFromTheFile(): void
    {
        [$container, $tenant] = $this->business();
        $upload = $this->upload(Pictures::withExif(Pictures::jpeg(300, 200), 6));

        $file = $this->library($container)->upload($upload, 'Ammonite on the shelf.png', 'An ammonite on a shelf');

        $entityManager = $container->getByType(EntityManagerInterface::class);
        $entityManager->clear();
        $read = $entityManager->getRepository(MediaFile::class)->find($file->id());

        self::assertInstanceOf(MediaFile::class, $read);
        self::assertSame($tenant->id(), $read->tenant()->id());
        self::assertSame($file->path(), $read->path());
        self::assertFileExists($this->directory . '/originals/' . $read->path());
        self::assertSame('Ammonite on the shelf.png', $read->originalName());
        self::assertSame('image/jpeg', $read->mime(), 'the type is the content\'s, not the name\'s');
        self::assertSame([200, 300], [$read->width(), $read->height()], 'as it is seen, not as it was stored');
        self::assertSame(filesize($upload), $read->sizeBytes());
        self::assertSame('An ammonite on a shelf', $read->alt());
    }

    /**
     * The name a file came with is kept to show somebody, never to store
     * under; a longer one than the column takes is cut rather than refused,
     * because the upload is fine and only its label is long.
     */
    public function testANameLongerThanTheColumnIsCut(): void
    {
        [$container] = $this->business();
        $name = str_repeat('trilobite ', 30) . '.jpg';

        $file = $this->library($container)->upload($this->upload(Pictures::jpeg(40, 30)), $name);

        self::assertSame(mb_substr($name, 0, 255), $file->originalName());
    }

    /**
     * Whose a file is has to be settled before a byte of it is written, or a
     * refusal would leave files nobody's row points at.
     */
    public function testAnUploadOutsideABusinessWritesNothing(): void
    {
        $container = $this->emptyDatabase();

        try {
            $this->library($container)->upload($this->upload(Pictures::jpeg(40, 30)), 'ammonite.jpg');
            self::fail('an upload outside a business should have been refused');
        } catch (TenancyRefused) {
        }

        self::assertSame([], $this->filesUnder($this->directory . '/originals'));
        self::assertSame([], $this->filesUnder($this->directory . '/public'));
    }

    public function testARefusedUploadRecordsNothing(): void
    {
        [$container] = $this->business();

        try {
            $this->library($container)->upload($this->upload('<svg xmlns="http://www.w3.org/2000/svg"/>'), 'logo.svg');
            self::fail('an SVG should have been refused');
        } catch (UploadRefused) {
        }

        self::assertSame([], $container->getByType(EntityManagerInterface::class)->getRepository(MediaFile::class)->findAll());
    }

    /**
     * The other half of "both or neither": the files were written and the row
     * could not be, so the files go too. The row is made to fail here by the
     * database itself - a description longer than its column - which is a
     * failure the library cannot see coming.
     */
    public function testARowThatCannotBeWrittenTakesItsFilesWithIt(): void
    {
        [$container] = $this->business();

        try {
            $this->library($container)->upload($this->upload(Pictures::jpeg(40, 30)), 'ammonite.jpg', str_repeat('a', 300));
            self::fail('the database should have refused the row');
        } catch (DriverException $refused) {
            self::assertStringContainsString('alt', $refused->getMessage(), 'it was the row that failed');
        }

        self::assertSame([], $this->filesUnder($this->directory . '/originals'));
        self::assertSame([], $this->filesUnder($this->directory . '/public'));
    }

    public function testTheVariantsAreOfferedForTheBrowserToChooseFrom(): void
    {
        [$container] = $this->business();
        $library = $this->library($container);

        $file = $library->upload($this->upload(Pictures::jpeg(2000, 1000)), 'ammonite.jpg');
        $stem = substr($file->path(), 0, -4);

        self::assertSame(
            sprintf('/shop/media/%1$s-thumb.jpg 240w, /shop/media/%1$s-card.jpg 800w, /shop/media/%1$s-large.jpg 1600w', $stem),
            $library->srcset($file, '/shop'),
        );
    }

    /** A picture smaller than a variant has that variant at its own size, and a width is offered once. */
    public function testASmallPictureOffersItsWidthOnce(): void
    {
        [$container] = $this->business();
        $library = $this->library($container);

        $file = $library->upload($this->upload(Pictures::png(200, 100)), 'ammonite.png');

        self::assertSame(sprintf('/media/%s-thumb.png 200w', substr($file->path(), 0, -4)), $library->srcset($file));
    }

    public function testTheApplicationHasALibrary(): void
    {
        self::assertInstanceOf(MediaLibrary::class, Boot::coreAlone()->getByType(MediaLibrary::class));
    }

    private function library(Container $container): MediaLibrary
    {
        return new MediaLibrary(
            new MediaStorage($this->directory . '/originals', $this->directory . '/public'),
            $container->getByType(EntityManagerInterface::class),
            $container->getByType(Tenancy::class),
        );
    }

    /** @return array{Container, Tenant} */
    private function business(): array
    {
        $container = $this->emptyDatabase();

        return [$container, Tenants::enter($container)];
    }

    private function emptyDatabase(): Container
    {
        $this->schema = Database::schemaFor(self::class);
        $container = Boot::coreAlone();
        Migrations::run($container);

        return $container;
    }

    private function upload(string $bytes): string
    {
        $file = $this->directory . '/uploads/' . bin2hex(random_bytes(4));
        FileSystem::write($file, $bytes);

        return $file;
    }

    /** @return list<string> */
    private function filesUnder(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        foreach (Finder::findFiles('*')->from($directory) as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }
}
