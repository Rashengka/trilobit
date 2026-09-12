<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Styleguide\HtmlSource;

/**
 * The HTML shown under a specimen is laid out afresh rather than shown the way
 * the templates left it - and the new layout is only whitespace: read back, it
 * is the same page a browser draws.
 *
 * The first half is asked of every specimen of every page of the style guide,
 * not of a fixture: the specimens are the HTML the formatter is actually given,
 * every page of the guide is reached through the router like any other page
 * (Trilobit\Tests\Template\StyleguideSpecimens), and the specimens drawn
 * without their code under them count as much as the ones with it - they are
 * HTML the same components print. What "the same page" means is decided by
 * MarkupTree, apart from the formatter, so that a mistake in one is not
 * mirrored by the other.
 *
 * The second half is what the layout is for: the HTML under a specimen reads
 * as if written by hand - no tag cut in two with its closing bracket on a line
 * of its own, and every block closed on the indentation it was opened on.
 *
 * Both are also run over HTML made to break them, because a rule that finds
 * nothing reads the same whether it is right or looking in the wrong place.
 */
#[CoversClass(HtmlSource::class)]
final class StyleguideFormatsTheHtmlOfEverySpecimenTest extends TestCase
{
    /**
     * What was drawn on the stage of every specimen of every page of the
     * guide - the HTML the formatter is given.
     *
     * @return iterable<string, array{string}>
     */
    public static function everySpecimen(): iterable
    {
        foreach (StyleguideSpecimens::everyPage() as $path => $page) {
            foreach ($page->querySelectorAll(sprintf('[%s]', StyleguideSpecimens::VARIANT)) as $specimen) {
                for ($child = $specimen->firstElementChild; $child instanceof Element; $child = $child->nextElementSibling) {
                    if (preg_match('/(?:^|\s)sg-specimen__stage(?:\s|$)/', $child->getAttribute('class') ?? '') === 1) {
                        yield $path . ' ' . ($specimen->getAttribute(StyleguideSpecimens::VARIANT) ?? '') => [$child->innerHTML];
                    }
                }
            }
        }
    }

    /**
     * The HTML shown under every specimen that shows its code, as a reader
     * sees it.
     *
     * @return iterable<string, array{string}>
     */
    public static function everyPreview(): iterable
    {
        foreach (StyleguideSpecimens::everyPage() as $path => $page) {
            foreach ($page->querySelectorAll(sprintf('[%s]', StyleguideSpecimens::VARIANT)) as $specimen) {
                // Under the stage, not on it: a specimen may show a block of
                // HTML as what it is a specimen of.
                $html = $specimen->querySelector('.sg-source code.language-markup');
                if ($html instanceof Element) {
                    yield $path . ' ' . ($specimen->getAttribute(StyleguideSpecimens::VARIANT) ?? '') => [$html->textContent ?? ''];
                }
            }
        }
    }

    #[DataProvider('everySpecimen')]
    public function testTheLayoutOfEverySpecimenIsTheSamePage(string $html): void
    {
        self::assertSame(MarkupTree::of($html), MarkupTree::of(HtmlSource::format($html)));
    }

    #[DataProvider('everyPreview')]
    public function testTheHtmlUnderEverySpecimenReadsAsWrittenByHand(string $html): void
    {
        self::assertSame([], $this->unevenLines($html), $html);
    }

    /** The page is not the same when a space a browser draws is lost - here, the one between two links. */
    public function testTheSamePageIsNotClaimedWhenASpaceBetweenInlineElementsGoes(): void
    {
        self::assertNotSame(
            MarkupTree::of("<p><a href=\"#a\">One</a>\n<a href=\"#b\">two</a></p>"),
            MarkupTree::of('<p><a href="#a">One</a><a href="#b">two</a></p>'),
        );
    }

    /** Nor when a space appears inside an element where there was none, which moves the word in it. */
    public function testTheSamePageIsNotClaimedWhenASpaceAppearsInsideAnInlineElement(): void
    {
        self::assertNotSame(
            MarkupTree::of('<a href="#">Leave</a>'),
            MarkupTree::of("<a href=\"#\">\n    Leave\n</a>"),
        );
    }

    /** Nor when a line inside pre moves, where every character is content. */
    public function testTheSamePageIsNotClaimedWhenPreformattedTextMoves(): void
    {
        self::assertNotSame(MarkupTree::of("<pre>one\n  two</pre>"), MarkupTree::of("<pre>one\ntwo</pre>"));
    }

    /** And it is claimed where the whitespace is only between and around blocks and between shapes of a drawing. */
    public function testTheSamePageIsClaimedWhenOnlyWhitespaceBetweenBlocksChanges(): void
    {
        self::assertSame(
            MarkupTree::of('<ul><li>One <em>two</em></li><li><svg><path d="M0"/><path d="M1"/></svg></li></ul>'),
            MarkupTree::of("<ul>\n    <li>\n        One   <em>two</em>\n    </li>\n  <li><svg>\n  <path d=\"M0\" />\n <path d=\"M1\" /></svg></li>\n</ul>"),
        );
    }

    /** What the templates used to leave under a component, before it was laid out. */
    public function testTheRuleReportsATagCutInTwoAndABlockClosedElsewhere(): void
    {
        self::assertSame(
            [
                'line 2 is only the end of a tag: ">"',
                'line 5 closes thead on 0 spaces, opened on 4',
                'line 6 closes table on 0 spaces, opened on 4',
                'line 7 closes div, which no line of its own opened',
            ],
            $this->unevenLines("<div class=\"c-table\"\n>\n    <table>\n    <thead>        <tr><th>x</th></tr>\n</thead>\n</table>\n</div>"),
        );
    }

    public function testTheRuleReportsABlockLeftOpen(): void
    {
        self::assertSame(['div opened on 0 spaces is never closed on a line of its own'], $this->unevenLines("<div>\n    <p>x</p></div>"));
    }

    public function testTheRuleLeavesHtmlLaidOutByHand(): void
    {
        self::assertSame(
            [],
            $this->unevenLines("<ul class=\"list\">\n    <li>One <a href=\"#\">two</a></li>\n    <li><input type=\"text\"></li>\n"
            . "    <li>\n        <svg viewBox=\"0 0 1 1\">\n            <path d=\"M0 0\" />\n        </svg>\n    </li>\n"
            . "    <li>\n        <a href=\"#\">Leave\n            <svg viewBox=\"0 0 1 1\">\n                <path d=\"M0 0\" />\n"
            . "            </svg>\n        </a>\n    </li>\n</ul>"),
        );
    }

    /**
     * What keeps a piece of HTML from reading as written by hand, line by line.
     *
     * A line that starts with an opening tag and does not close it on the same
     * line opens a block - also when a word follows the tag, which is where a
     * word printed straight after it stays. The block has to be closed by a
     * line that is only its closing tag, on the same indentation. Every other
     * line is content and is left alone, except a line that is nothing but the
     * end of a tag.
     *
     * @return list<string>
     */
    private function unevenLines(string $html): array
    {
        $problems = [];
        $open = [];
        foreach (explode("\n", $html) as $index => $line) {
            $number = $index + 1;
            $indent = strspn($line, ' ');
            $text = trim($line);

            if (preg_match('#^/?>$#', $text) === 1) {
                $problems[] = sprintf('line %d is only the end of a tag: "%s"', $number, $text);
            } elseif (
                preg_match('#^<([a-zA-Z][^\s/>]*)(?:\s[^<>]*)?(?<!/)>#', $text, $match) === 1
                && !$this->isVoid($match[1])
                && !str_contains($text, '</' . $match[1] . '>')
            ) {
                $open[] = [$match[1], $indent];
            } elseif (preg_match('#^</([a-zA-Z][^\s/>]*)>$#', $text, $match) === 1) {
                $opened = end($open);
                if ($opened === false || $opened[0] !== $match[1]) {
                    $problems[] = sprintf('line %d closes %s, which no line of its own opened', $number, $match[1]);

                    continue;
                }

                array_pop($open);
                if ($opened[1] !== $indent) {
                    $problems[] = sprintf('line %d closes %s on %d spaces, opened on %d', $number, $match[1], $indent, $opened[1]);
                }
            }
        }

        foreach ($open as [$name, $indent]) {
            $problems[] = sprintf('%s opened on %d spaces is never closed on a line of its own', $name, $indent);
        }

        return $problems;
    }

    private function isVoid(string $name): bool
    {
        return in_array(strtolower($name), ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'], true);
    }
}
