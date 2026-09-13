<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Listing;

/**
 * The page of a listing an address asks for.
 *
 * Read here rather than declared as a typed persistent parameter, because
 * Nette's answer to `?page=abc` on one of those is a 404 - which tells
 * somebody holding a link that a list that exists does not. What is a page
 * number is that page; anything else is the first page, with a sentence
 * saying why. Whether the number is a page the list has is only known once it
 * is counted, and that is Slice's to say.
 */
final readonly class RequestedPage
{
    /** The name the address carries it under, beside the filters, which may therefore not be called this. */
    public const string PARAMETER = 'page';

    /** A page number: no nought, no sign, no leading nought, and not more digits than an integer holds. */
    private const string NUMBER = '/^[1-9][0-9]{0,8}$/';

    private function __construct(
        public int $number,
        public ?string $setAside,
    ) {}

    public static function read(mixed $raw): self
    {
        if ($raw === null) {
            return new self(1, null);
        }

        if (is_string($raw) && preg_match(self::NUMBER, $raw) === 1) {
            return new self((int) $raw, null);
        }

        if (!is_string($raw)) {
            return new self(1, 'The page was given more than one value, so the first page is shown.');
        }

        return new self(1, sprintf('There is no page "%s", so the first page is shown.', mb_substr($raw, 0, 20)));
    }
}
