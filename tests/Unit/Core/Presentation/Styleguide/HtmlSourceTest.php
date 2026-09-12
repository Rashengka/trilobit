<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Presentation\Styleguide;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Styleguide\HtmlSource;

/**
 * The HTML under a specimen, laid out the way somebody would write it by hand:
 * a block to a line and indented by what holds it, words and the elements
 * among them on the line they are in - whatever shape the templates that
 * printed it happened to leave it in.
 *
 * Every case here is written out in full on both sides, because what is under
 * test is a shape and a shape is only seen whole. That the shape changes
 * nothing a browser draws is held over every specimen of the style guide by
 * Trilobit\Tests\Template\StyleguideFormatsTheHtmlOfEverySpecimenTest.
 */
#[CoversClass(HtmlSource::class)]
final class HtmlSourceTest extends TestCase
{
    /**
     * What a component made of nested blocks leaves behind: the closing
     * bracket of a tag written over several lines on a line of its own, a
     * block printed straight after the tag that opens its parent, and a
     * closing tag back at the left edge.
     */
    public function testBlocksNestedByTemplatesComeOutOneToALineIndentedByWhatHoldsThem(): void
    {
        self::assertSame(
            <<<'HTML'
                <div class="frame">
                    <table class="grid">
                        <thead>
                            <tr>
                                <th scope="col">Drawer</th>
                                <th scope="col">Room</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Head shields</td>
                                <td>East</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                HTML,
            HtmlSource::format(<<<'HTML'
                    <div class="frame"
                    >
                        <table class="grid">

                            <thead>                        <tr>
                                    <th scope="col">Drawer</th>
                                    <th scope="col">Room</th>
                                </tr>
                </thead>

                            <tbody>            <tr><td>Head shields</td>
                <td>East</td></tr>
                </tbody>
                        </table>
                    </div>
                HTML),
        );
    }

    /** Words and the inline elements among them are one line, however many the template spread them over. */
    public function testTextAndTheInlineElementsInItStayOnOneLine(): void
    {
        self::assertSame(
            '<p class="note">Open the <a href="#drawer">drawer</a>, <em>then</em> the <code>box</code>.</p>',
            HtmlSource::format(<<<'HTML'
                <p class="note">
                    Open the <a href="#drawer">drawer</a>,
                    <em>then</em>   the
                    <code>box</code>.
                </p>
                HTML),
        );
    }

    /**
     * Inside an inline element a line may only break where there already was
     * whitespace: a break is drawn as a space, and a space that was not there
     * would move the word. So the word written straight after the tag stays
     * straight after it, and the drawing that was on a line of its own gets
     * one.
     */
    public function testALineInsideAnInlineElementBreaksOnlyWhereThereWasWhitespace(): void
    {
        self::assertSame(
            <<<'HTML'
                <a class="button" href="#">Leave
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M1 1" />
                        <path d="M2 2" />
                    </svg>
                </a>
                HTML,
            HtmlSource::format(<<<'HTML'
                <a class="button" href="#"
                >Leave
                <svg
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                        <path d="M1 1" />
                        <path d="M2 2" />
                </svg>
                </a>
                HTML),
        );
    }

    /** A drawing that sits in a line of text is part of that line. */
    public function testADrawingInsideALineOfTextStaysInIt(): void
    {
        self::assertSame(
            '<p>Press <svg viewBox="0 0 1 1"><path d="M0 0" /></svg> to open the drawer.</p>',
            HtmlSource::format("<p>\n    Press <svg viewBox=\"0 0 1 1\">\n        <path d=\"M0 0\" />\n    </svg> to open the drawer.\n</p>"),
        );
    }

    /**
     * Elements that stood on lines of their own with no text between them keep
     * those lines; the same elements written on one line keep that.
     */
    public function testElementsSideBySideKeepTheLinesTheyWereWrittenOn(): void
    {
        self::assertSame(
            <<<'HTML'
                <div class="choices">
                    <span>Theme</span>
                    <button type="button">Light</button>
                    <button type="button">Dark</button>
                </div>
                <p><span>One</span> <span>two</span></p>
                HTML,
            HtmlSource::format(<<<'HTML'
                <div class="choices"><span>Theme</span>

                <button type="button"
                >Light</button>
                <button type="button"
                >Dark</button>
                    </div>
                <p><span>One</span> <span>two</span></p>
                HTML),
        );
    }

    /** What pre, textarea, script and style hold is not layout, it is their content, and it is left alone. */
    public function testPreformattedAndScriptedContentIsKeptByteForByte(): void
    {
        self::assertSame(
            <<<'HTML'
                <div>
                    <pre><code>  one
                    two &lt; three</code></pre>
                    <textarea name="note">

                  keep  this</textarea>
                    <script>if (a < b) { go(); }
                </script>
                    <style>p > a { color: red; }</style>
                </div>
                HTML,
            HtmlSource::format(<<<'HTML'
                  <div>
                <pre><code>  one
                    two &lt; three</code></pre>
                      <textarea name="note">

                  keep  this</textarea>
                <script>if (a < b) { go(); }
                </script>
                   <style>p > a { color: red; }</style>
                  </div>
                HTML),
        );
    }

    /**
     * An element that cannot hold anything is written without a closing tag,
     * an attribute that is on by being there is written without a value when
     * it has none, and every attribute keeps its order and its value - the
     * value of disabled="disabled" is not nothing, so it stays.
     */
    public function testVoidElementsAndBooleanAttributesAreWrittenShort(): void
    {
        self::assertSame(
            <<<'HTML'
                <form>
                    <input type="checkbox" name="rare" checked disabled="disabled">
                    <img src="shell.png" alt="">
                    <hr>
                    <button type="button" hidden popover data-empty="">Go</button>
                </form>
                HTML,
            HtmlSource::format(<<<'HTML'
                <form>
                <input type="checkbox" name="rare" checked="" disabled="disabled">
                <img src="shell.png" alt="" />
                <hr>
                <button type="button" hidden="" popover data-empty="">Go</button>
                </form>
                HTML),
        );
    }

    /** Characters that mean something in HTML are written the way HTML needs them, in text and in attributes. */
    public function testTextAndAttributesAreEscapedAsHtml(): void
    {
        self::assertSame(
            '<a href="?drawer=1&amp;shelf=2" title="&quot;Rare&quot; &amp; old">Shells &amp; stones &lt;3&gt;&nbsp;—</a>',
            HtmlSource::format('<a href="?drawer=1&shelf=2" title=\'"Rare" &amp; old\'>Shells &amp; stones &lt;3&gt;&nbsp;&mdash;</a>'),
        );
    }

    /** A comment is kept where it is. */
    public function testACommentIsKept(): void
    {
        self::assertSame(
            "<ul>\n    <!-- the rare ones -->\n    <li>Ammonite</li>\n</ul>",
            HtmlSource::format("<ul>\n<!-- the rare ones -->\n        <li>Ammonite</li></ul>"),
        );
    }

    public function testNothingIsNothing(): void
    {
        self::assertSame('', HtmlSource::format("  \n  "));
    }
}
