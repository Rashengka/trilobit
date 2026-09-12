<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Presentation\Styleguide;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Styleguide\SourceText;

/**
 * A piece of source cut out of where it was written, set flush to the left
 * edge the way somebody would write it on its own.
 */
#[CoversClass(SourceText::class)]
final class SourceTextTest extends TestCase
{
    public function testTheIndentationEveryLineSharesIsTakenOff(): void
    {
        self::assertSame(
            "<ul>\n    <li>One</li>\n</ul>",
            SourceText::dedent("        <ul>\n            <li>One</li>\n        </ul>"),
        );
    }

    public function testEmptyLinesAtEitherEndAreDropped(): void
    {
        self::assertSame('<p>One</p>', SourceText::dedent("\n\n    <p>One</p>\n    \n"));
    }

    /** An empty line in the middle has no indentation to share, and does not stop the rest being shared. */
    public function testAnEmptyLineInTheMiddleIsKeptAndDoesNotCount(): void
    {
        self::assertSame("<p>One</p>\n\n<p>Two</p>", SourceText::dedent("    <p>One</p>\n\n    <p>Two</p>"));
    }

    /** Several in a row say nothing one of them does not, and a rendered template leaves plenty. */
    public function testARunOfEmptyLinesIsOne(): void
    {
        self::assertSame("<p>One</p>\n\n<p>Two</p>", SourceText::dedent("<p>One</p>\n   \n\n  \n<p>Two</p>"));
    }

    public function testWhitespaceAtTheEndOfALineIsDropped(): void
    {
        self::assertSame("<p>One</p>\n<p>Two</p>", SourceText::dedent("  <p>One</p>   \n  <p>Two</p>\t"));
    }

    /**
     * Where a template printed the first line straight after something else,
     * it arrives with no indentation of its own. It must not decide that no
     * line has any to share.
     */
    public function testAFirstLineWithoutIndentationDoesNotHoldTheOthersIn(): void
    {
        self::assertSame(
            "<div>\n    <p>One</p>\n</div>",
            SourceText::dedent("<div>\n            <p>One</p>\n        </div>"),
        );
    }

    /** A tab is one column, not four: mixing the two is the author's to sort out, not this. */
    public function testTabsAreIndentationToo(): void
    {
        self::assertSame("<p>\n\tOne\n</p>", SourceText::dedent("\t<p>\n\t\tOne\n\t</p>"));
    }

    public function testNothingIsNothing(): void
    {
        self::assertSame('', SourceText::dedent("  \n \n"));
    }
}
