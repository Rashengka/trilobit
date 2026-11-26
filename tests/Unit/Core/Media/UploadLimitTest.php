<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Media\MediaStorage;
use Trilobit\Core\Media\UploadLimit;

/**
 * How large a file a form on this server can take, and how to tell that PHP
 * turned one away before the application saw it.
 *
 * PHP refuses a file in two ways, and the second looks like nothing at all. A
 * file over `upload_max_filesize` arrives as an upload with an error code; a
 * request over `post_max_size` arrives with its whole body thrown away - no
 * fields, no files, and so no signal telling the form it was sent. Left alone,
 * the page is drawn again as if nobody had pressed anything. What is asserted
 * here is that both are recognised, and only they.
 */
#[CoversClass(UploadLimit::class)]
final class UploadLimitTest extends TestCase
{
    /** @return iterable<string, array{string|false, int}> */
    public static function settings(): iterable
    {
        yield 'megabytes' => ['20M', 20 * 1024 * 1024];
        yield 'megabytes in lower case' => ['8m', 8 * 1024 * 1024];
        yield 'gigabytes' => ['1G', 1024 * 1024 * 1024];
        yield 'kilobytes' => ['512K', 512 * 1024];
        yield 'bytes' => ['8388608', 8388608];
        yield 'no limit' => ['0', 0];
        yield 'nothing set' => ['', 0];
        yield 'no such setting' => [false, 0];
    }

    #[DataProvider('settings')]
    public function testAPhpSizeIsReadTheWayPhpReadsIt(string|false $setting, int $bytes): void
    {
        self::assertSame($bytes, UploadLimit::bytes($setting));
    }

    public function testTheLargestFileIsTheSmallestOfTheLimitsThatApply(): void
    {
        self::assertSame(2 * 1024 * 1024, new UploadLimit(2 * 1024 * 1024, 8 * 1024 * 1024)->largestFile());
        self::assertSame(1024 * 1024, new UploadLimit(2 * 1024 * 1024, 1024 * 1024)->largestFile());
        self::assertSame(MediaStorage::MAX_BYTES, new UploadLimit(64 * 1024 * 1024, 80 * 1024 * 1024)->largestFile());
        self::assertSame(MediaStorage::MAX_BYTES, new UploadLimit(0, 0)->largestFile(), 'no limit of PHP\'s leaves the library\'s');
    }

    public function testTheLargestFileIsSaidTheWayAPersonReadsIt(): void
    {
        self::assertSame('20 MB', new UploadLimit(64 * 1024 * 1024, 80 * 1024 * 1024)->describe());
        self::assertSame('2 MB', new UploadLimit(2 * 1024 * 1024, 8 * 1024 * 1024)->describe());
        self::assertSame('512 kB', new UploadLimit(512 * 1024, 8 * 1024 * 1024)->describe());
    }

    /** @return iterable<string, array{bool, bool, int|null, bool}> */
    public static function requests(): iterable
    {
        yield 'a body over the limit, thrown away' => [true, true, 9 * 1024 * 1024, true];
        yield 'an empty form sent within the limit' => [true, true, 120, false];
        yield 'a body over the limit that somehow arrived' => [true, false, 9 * 1024 * 1024, false];
        yield 'a request that is not sending anything' => [false, true, 9 * 1024 * 1024, false];
        yield 'no length said' => [true, true, null, false];
    }

    #[DataProvider('requests')]
    public function testABodyPhpThrewAwayIsToldFromAnEmptyOne(bool $isPost, bool $arrivedEmpty, ?int $length, bool $dropped): void
    {
        self::assertSame($dropped, new UploadLimit(2 * 1024 * 1024, 8 * 1024 * 1024)->droppedBody($isPost, $arrivedEmpty, $length));
    }

    public function testWithoutALimitOnTheBodyNothingIsThrownAway(): void
    {
        self::assertFalse(new UploadLimit(0, 0)->droppedBody(true, true, 9 * 1024 * 1024));
    }

    public function testAnUploadRefusedForItsSizeIsToldFromOtherFailures(): void
    {
        $limit = new UploadLimit(2 * 1024 * 1024, 8 * 1024 * 1024);

        self::assertTrue($limit->refusedForItsSize(UPLOAD_ERR_INI_SIZE));
        self::assertTrue($limit->refusedForItsSize(UPLOAD_ERR_FORM_SIZE));
        self::assertFalse($limit->refusedForItsSize(UPLOAD_ERR_PARTIAL));
        self::assertFalse($limit->refusedForItsSize(UPLOAD_ERR_OK));
    }
}
