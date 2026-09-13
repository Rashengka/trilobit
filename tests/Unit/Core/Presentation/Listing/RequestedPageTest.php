<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Presentation\Listing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Listing\RequestedPage;

/**
 * The page an address asks for.
 *
 * Nette's own answer to `?page=abc` on a typed parameter is a 404, which
 * tells somebody holding a link that a list that exists does not. So the
 * number is read here: what is a page number is one, and anything else is
 * the first page with a sentence saying so.
 */
#[CoversClass(RequestedPage::class)]
final class RequestedPageTest extends TestCase
{
    public function testNoPageIsTheFirstAndSaysNothing(): void
    {
        $page = RequestedPage::read(null);

        self::assertSame(1, $page->number);
        self::assertNull($page->setAside);
    }

    public function testANumberIsThatPage(): void
    {
        $page = RequestedPage::read('3');

        self::assertSame(3, $page->number);
        self::assertNull($page->setAside);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function whatIsNoPage(): iterable
    {
        yield 'a word' => ['abc'];
        yield 'nought' => ['0'];
        yield 'below one' => ['-1'];
        yield 'a fraction' => ['1.5'];
        yield 'a leading nought' => ['02'];
        yield 'more digits than any list has pages' => ['99999999999999999999'];
        yield 'an empty string' => [''];
        yield 'several values' => [['1', '2']];
    }

    #[DataProvider('whatIsNoPage')]
    public function testWhatIsNoPageIsTheFirstWithASentence(mixed $raw): void
    {
        $page = RequestedPage::read($raw);

        self::assertSame(1, $page->number);
        self::assertNotNull($page->setAside);
    }
}
