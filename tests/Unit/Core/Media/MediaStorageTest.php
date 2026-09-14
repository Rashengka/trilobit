<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Media;

use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Media\ImageType;
use Trilobit\Core\Media\MediaNotStored;
use Trilobit\Core\Media\MediaStorage;
use Trilobit\Core\Media\Refusal;
use Trilobit\Core\Media\UploadRefused;
use Trilobit\Core\Media\Variant;
use Trilobit\Tests\Pictures;

/**
 * Where the bytes of a picture go, and what is done to them on the way.
 *
 * Two directories and one rule between them. The original is kept whole and
 * outside the public directory, because a phone writes where a photo was taken
 * into it. What is published are the variants, made from it by decoding and
 * encoding again - which is what drops that position, and which these cases
 * check on the bytes rather than trust.
 */
#[CoversClass(MediaStorage::class)]
final class MediaStorageTest extends TestCase
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

    public function testTheOriginalIsKeptWholeAndOutsideThePublicDirectory(): void
    {
        $upload = $this->upload('ammonite.jpg', Pictures::jpeg(2000, 1000));

        $stored = $this->storage()->store($upload);

        self::assertMatchesRegularExpression('~^[0-9a-f]{2}/[0-9a-f]{32}\.jpg$~', $stored->path);
        self::assertFileEquals($upload, $this->originals() . '/' . $stored->path);
        self::assertFileDoesNotExist($this->public() . '/' . $stored->path);
        self::assertSame(ImageType::Jpeg, $stored->type);
        self::assertSame([2000, 1000], [$stored->width, $stored->height]);
        self::assertSame(filesize($upload), $stored->bytes);
    }

    public function testEveryVariantIsPublishedAtItsSize(): void
    {
        $stored = $this->storage()->store($this->upload('ammonite.jpg', Pictures::jpeg(2000, 1000)));

        $sizes = [];
        foreach (Variant::cases() as $variant) {
            $picture = Pictures::open($this->public() . '/' . MediaStorage::variantPath($stored->path, $variant));
            $sizes[$variant->value] = [imagesx($picture), imagesy($picture)];
        }

        self::assertSame(['thumb' => [240, 120], 'card' => [800, 400], 'large' => [1600, 800]], $sizes);
    }

    /**
     * The name of the upload and the type the browser claimed are both written
     * by the person uploading, so neither of them decides anything: a JPEG
     * called .png is kept as the JPEG it is, under a name of the library's own.
     */
    public function testTheTypeIsReadFromTheContentAndTheNameIsNotKept(): void
    {
        $stored = $this->storage()->store($this->upload('ammonite.png', Pictures::jpeg(40, 30)));

        self::assertSame(ImageType::Jpeg, $stored->type);
        self::assertStringEndsWith('.jpg', $stored->path);
        self::assertStringNotContainsString('ammonite', $stored->path);
    }

    /**
     * @return iterable<string, array{string, ImageType}>
     */
    public static function acceptedTypes(): iterable
    {
        yield 'PNG' => [Pictures::png(60, 40), ImageType::Png];
        yield 'WebP' => [Pictures::webp(60, 40), ImageType::Webp];
    }

    #[DataProvider('acceptedTypes')]
    public function testAPngAndAWebpAreTakenAndPublishedAsWhatTheyAre(string $bytes, ImageType $type): void
    {
        $stored = $this->storage()->store($this->upload('upload', $bytes));

        self::assertSame($type, $stored->type);
        self::assertStringEndsWith('.' . $type->extension(), $stored->path);

        $variant = $this->public() . '/' . MediaStorage::variantPath($stored->path, Variant::Thumb);
        $detected = getimagesize($variant);
        self::assertIsArray($detected);
        self::assertSame($type->mime(), $detected['mime']);
        self::assertSame(
            [Pictures::RED, Pictures::GREEN, Pictures::BLUE, Pictures::YELLOW],
            Pictures::quartersOf(Pictures::open($variant)),
        );
    }

    public function testWhatIsSeeThroughInAPngStaysSeeThrough(): void
    {
        $stored = $this->storage()->store($this->upload('upload', Pictures::transparentPng(60, 40)));

        $variant = Pictures::open($this->public() . '/' . MediaStorage::variantPath($stored->path, Variant::Thumb));
        $clear = imagecolorat($variant, 10, 20);
        $solid = imagecolorat($variant, 50, 20);
        self::assertIsInt($clear);
        self::assertIsInt($solid);

        self::assertSame(127, ($clear >> 24) & 0x7F, 'the left half was transparent');
        self::assertSame(0, ($solid >> 24) & 0x7F, 'the right half was opaque');
    }

    /**
     * What a phone writes when it was held some other way than upright, and
     * the quarters a viewer is meant to see.
     *
     * The picture is stored red, green / blue, yellow; the tag says how to
     * turn it. The expectations are written out per case rather than computed,
     * because computing them would mean writing the same transformation twice.
     *
     * @return iterable<string, array{int, list<string>}>
     */
    public static function orientations(): iterable
    {
        $r = Pictures::RED;
        $g = Pictures::GREEN;
        $b = Pictures::BLUE;
        $y = Pictures::YELLOW;

        yield '1, upright' => [1, [$r, $g, $b, $y]];
        yield '2, mirrored' => [2, [$g, $r, $y, $b]];
        yield '3, upside down' => [3, [$y, $b, $g, $r]];
        yield '4, mirrored upside down' => [4, [$b, $y, $r, $g]];
        yield '5, mirrored and on its side' => [5, [$r, $b, $g, $y]];
        yield '6, turned a quarter to the right' => [6, [$b, $r, $y, $g]];
        yield '7, mirrored and on its other side' => [7, [$y, $g, $b, $r]];
        yield '8, turned a quarter to the left' => [8, [$g, $y, $r, $b]];
    }

    /** @param list<string> $expected */
    #[DataProvider('orientations')]
    public function testAPhotoIsPublishedTheWayUpItWasTaken(int $orientation, array $expected): void
    {
        $upload = $this->upload('photo.jpg', Pictures::withExif(Pictures::jpeg(300, 200), $orientation));
        $exif = exif_read_data($upload);
        self::assertIsArray($exif);
        self::assertSame($orientation, $exif['Orientation'] ?? null, 'the test photo carries the turn it claims to');

        $stored = $this->storage()->store($upload);

        $onItsSide = $orientation >= 5;
        self::assertSame($onItsSide ? [200, 300] : [300, 200], [$stored->width, $stored->height]);

        foreach (Variant::cases() as $variant) {
            $picture = Pictures::open($this->public() . '/' . MediaStorage::variantPath($stored->path, $variant));
            self::assertSame($variant->fit($stored->width, $stored->height), [imagesx($picture), imagesy($picture)]);
            self::assertSame($expected, Pictures::quartersOf($picture), $variant->value);
        }
    }

    /**
     * The reason the original is not public, checked on the bytes: the upload
     * carries a position, the original keeps it, and not one variant has it.
     */
    public function testNoVariantCarriesWhereThePhotoWasTaken(): void
    {
        $upload = $this->upload('photo.jpg', Pictures::withExif(Pictures::jpeg(300, 200), 6));

        $stored = $this->storage()->store($upload);

        $original = exif_read_data($this->originals() . '/' . $stored->path);
        self::assertIsArray($original);
        self::assertSame(['12/1', '34/1', '56/1'], $original['GPSLatitude'] ?? null, 'the original keeps the position');

        foreach (Variant::cases() as $variant) {
            $file = $this->public() . '/' . MediaStorage::variantPath($stored->path, $variant);
            $bytes = file_get_contents($file);
            self::assertIsString($bytes);
            self::assertStringNotContainsString("Exif\0\0", $bytes, $variant->value . ' has no EXIF block at all');

            $read = exif_read_data($file);
            self::assertArrayNotHasKey('GPSLatitude', is_array($read) ? $read : [], $variant->value);
        }
    }

    /**
     * @return iterable<string, array{string, Refusal}>
     */
    public static function refusedFiles(): iterable
    {
        yield 'an SVG, which can carry script' => [
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            Refusal::NotAPicture,
        ];
        yield 'a GIF, which is a picture the library does not take' => [Pictures::gif(40, 30), Refusal::NotAPicture];
        yield 'a script called a JPEG' => ['<?php echo "hello";', Refusal::NotAPicture];
        yield 'an empty file' => ['', Refusal::NotAPicture];
        yield 'a header that promises more pixels than may be decoded' => [
            Pictures::pngClaiming(20_000, 20_000),
            Refusal::TooManyPixels,
        ];
        yield 'one pixel wider than the limit allows, however few bytes it has' => [
            Pictures::pngClaiming(MediaStorage::MAX_PIXELS + 1, 1),
            Refusal::TooManyPixels,
        ];
    }

    #[DataProvider('refusedFiles')]
    public function testARefusedFileLeavesNothingBehind(string $bytes, Refusal $reason): void
    {
        $upload = $this->upload('holiday.jpg', $bytes);

        try {
            $this->storage()->store($upload);
            self::fail('the file should have been refused');
        } catch (UploadRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }

        self::assertSame([], $this->filesUnder($this->originals()));
        self::assertSame([], $this->filesUnder($this->public()));
    }

    /**
     * The limit on bytes, shown with a file that is a perfectly good picture
     * apart from its length - so that it is the length that is refused.
     */
    public function testAFileLargerThanTheLimitIsRefusedBeforeItIsRead(): void
    {
        $picture = Pictures::png(20, 20);
        $upload = $this->upload('large.png', str_pad($picture, MediaStorage::MAX_BYTES + 1, "\0"));
        self::assertIsArray(getimagesize($upload), 'apart from its length it is a picture');

        try {
            $this->storage()->store($upload);
            self::fail('the file should have been refused');
        } catch (UploadRefused $refused) {
            self::assertSame(Refusal::TooLarge, $refused->reason);
        }

        self::assertSame([], $this->filesUnder($this->originals()));
    }

    /**
     * A variant that cannot be written fails the whole upload, loudly, and
     * takes back everything written before it - the original and the smaller
     * variants alike. What stood in the way is not touched: it was not written
     * by this upload.
     */
    public function testAVariantThatCannotBeWrittenLeavesNothingOfTheUploadBehind(): void
    {
        $name = str_repeat('ab', 16);
        $storage = $this->storage(static fn(): string => $name);
        $obstacle = $this->public() . '/' . MediaStorage::variantPath('ab/' . $name . '.jpg', Variant::Large);
        FileSystem::createDir($obstacle);

        try {
            $storage->store($this->upload('ammonite.jpg', Pictures::jpeg(2000, 1000)));
            self::fail('writing the large variant should have failed');
        } catch (MediaNotStored $failure) {
            self::assertStringContainsString('-large.jpg', $failure->getMessage());
        }

        self::assertSame([], $this->filesUnder($this->originals()));
        self::assertSame([], $this->filesUnder($this->public()));
        self::assertDirectoryExists($obstacle);
    }

    public function testANameThatIsTakenIsNotWrittenOver(): void
    {
        $name = str_repeat('cd', 16);
        $storage = $this->storage(static fn(): string => $name);
        $first = $storage->store($this->upload('first.jpg', Pictures::jpeg(40, 30)));
        $kept = (string) file_get_contents($this->originals() . '/' . $first->path);

        try {
            $storage->store($this->upload('second.jpg', Pictures::jpeg(60, 20)));
            self::fail('the second file should not have been stored under the first one\'s name');
        } catch (MediaNotStored $failure) {
            self::assertStringContainsString('already exists', $failure->getMessage());
        }

        self::assertStringEqualsFile($this->originals() . '/' . $first->path, $kept);
        $thumb = Pictures::open($this->public() . '/' . MediaStorage::variantPath($first->path, Variant::Thumb));
        self::assertSame([40, 30], [imagesx($thumb), imagesy($thumb)], 'the first file\'s variant is still its own');
    }

    public function testRegeneratingMakesTheVariantsAgainFromTheOriginal(): void
    {
        $storage = $this->storage();
        $stored = $storage->store($this->upload('photo.jpg', Pictures::withExif(Pictures::jpeg(300, 200), 6)));
        $thumb = $this->public() . '/' . MediaStorage::variantPath($stored->path, Variant::Thumb);
        $card = $this->public() . '/' . MediaStorage::variantPath($stored->path, Variant::Card);
        FileSystem::delete($thumb);
        FileSystem::write($card, 'not a picture any more');

        $storage->regenerate($stored->path);

        foreach ([$thumb, $card] as $file) {
            $picture = Pictures::open($file);
            self::assertSame([Pictures::BLUE, Pictures::RED, Pictures::YELLOW, Pictures::GREEN], Pictures::quartersOf($picture));
        }
    }

    public function testRegeneratingWithoutAnOriginalIsLoud(): void
    {
        $this->expectException(MediaNotStored::class);
        $this->expectExceptionMessage('There is no original');

        $this->storage()->regenerate('ef/' . str_repeat('ef', 16) . '.jpg');
    }

    public function testTheOriginalsAreListedByTheirStoredNames(): void
    {
        $storage = $this->storage();
        $first = $storage->store($this->upload('first.jpg', Pictures::jpeg(40, 30)));
        $second = $storage->store($this->upload('second.png', Pictures::png(40, 30)));

        $expected = [$first->path, $second->path];
        sort($expected);

        self::assertSame($expected, $storage->originals());
    }

    public function testNothingIsListedBeforeAnythingWasStored(): void
    {
        self::assertSame([], $this->storage()->originals());
    }

    public function testDiscardingRemovesTheOriginalAndEveryVariant(): void
    {
        $storage = $this->storage();
        $stored = $storage->store($this->upload('ammonite.jpg', Pictures::jpeg(40, 30)));

        $storage->discard($stored->path);

        self::assertSame([], $this->filesUnder($this->originals()));
        self::assertSame([], $this->filesUnder($this->public()));
    }

    /** @param (\Closure(): string)|null $names */
    private function storage(?\Closure $names = null): MediaStorage
    {
        return new MediaStorage($this->originals(), $this->public(), $names);
    }

    private function upload(string $name, string $bytes): string
    {
        $file = $this->directory . '/uploads/' . bin2hex(random_bytes(4)) . '/' . $name;
        FileSystem::write($file, $bytes);

        return $file;
    }

    private function originals(): string
    {
        return $this->directory . '/originals';
    }

    private function public(): string
    {
        return $this->directory . '/public';
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
