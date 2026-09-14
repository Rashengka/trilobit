<?php

declare(strict_types=1);

namespace Trilobit\Core\Media;

/**
 * How large a file a form on this server can take, and how to tell that PHP
 * turned one away before the application saw it.
 *
 * The media library takes pictures of up to MediaStorage::MAX_BYTES, but a
 * file sent from a form reaches it only through PHP, which has two limits of
 * its own and, as it comes, sets both lower: `upload_max_filesize` at 2M and
 * `post_max_size` at 8M. So the largest file a person may choose is the
 * smallest of the three, and a form that says anything else promises what the
 * server will not do.
 *
 * **PHP refuses in two ways, and the second looks like nothing at all.** A
 * file over `upload_max_filesize` arrives as an upload with an error code in
 * it, and a form can say so beside its field. A request over `post_max_size`
 * arrives with its whole body thrown away - no fields, no files, and therefore
 * no signal telling the application a form was sent - so the page is drawn
 * again as if nobody had pressed anything. droppedBody() recognises that from
 * what is left: a request that was sent, that says how long it was, that was
 * longer than the server takes, and that holds nothing.
 *
 * What the limits are is a matter of the server's configuration, not of this
 * application's; README.md, under "Pictures", says what to set and why.
 */
final readonly class UploadLimit
{
    private const int KILOBYTE = 1024;

    private const int MEGABYTE = 1024 * 1024;

    public function __construct(
        /** `upload_max_filesize` in bytes; 0 for no limit. */
        private int $uploadMaxFilesize,
        /** `post_max_size` in bytes; 0 for no limit. */
        private int $postMaxSize,
    ) {}

    /** The limits of the PHP this request is served by. */
    public static function ofThisServer(): self
    {
        return new self(self::bytes(ini_get('upload_max_filesize')), self::bytes(ini_get('post_max_size')));
    }

    /**
     * A size as PHP's configuration writes it - `20M`, `512K`, `1G`, a number
     * of bytes - in bytes; 0 for nothing, for no such setting, and for anything
     * PHP would not read as a limit.
     */
    public static function bytes(string|false $setting): int
    {
        if ($setting === false || preg_match('/^\s*(\d+)\s*([kmg]?)\s*$/i', $setting, $parts) !== 1) {
            return 0;
        }

        $number = (int) $parts[1];

        return match (strtolower($parts[2])) {
            'g' => $number * self::MEGABYTE * self::KILOBYTE,
            'm' => $number * self::MEGABYTE,
            'k' => $number * self::KILOBYTE,
            default => $number,
        };
    }

    /** The largest file a form on this server can take in, in bytes: the smallest limit that applies. */
    public function largestFile(): int
    {
        return min(array_filter(
            [MediaStorage::MAX_BYTES, $this->uploadMaxFilesize, $this->postMaxSize],
            static fn(int $limit): bool => $limit > 0,
        ));
    }

    /** largestFile() the way a person reads it: `20 MB`, `512 kB`. */
    public function describe(): string
    {
        $bytes = $this->largestFile();

        return $bytes >= self::MEGABYTE
            ? sprintf('%d MB', intdiv($bytes, self::MEGABYTE))
            : sprintf('%d kB', intdiv($bytes, self::KILOBYTE));
    }

    /**
     * Whether PHP threw away the body of a request because it was longer than
     * `post_max_size`: it was sent, it arrived with nothing in it, and it said
     * it was longer than the server takes. An empty form sent within the limit
     * is none of that.
     */
    public function droppedBody(bool $isPost, bool $arrivedEmpty, ?int $contentLength): bool
    {
        return $isPost
            && $arrivedEmpty
            && $this->postMaxSize > 0
            && $contentLength !== null
            && $contentLength > $this->postMaxSize;
    }

    /** Whether an upload was refused by PHP for its size rather than for anything else. */
    public function refusedForItsSize(int $uploadError): bool
    {
        return $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE;
    }
}
