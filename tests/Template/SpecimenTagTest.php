<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Latte\CompileException;
use Latte\Engine;
use Latte\Loaders\StringLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Styleguide\SpecimenExtension;
use Trilobit\Core\Presentation\Styleguide\SpecimenNode;

/**
 * {specimen} draws what it wraps once, and hands the style guide's frame both
 * of the things a reader wants to copy: the HTML it came out as, and the Latte
 * it was written in - each from the one place, and neither typed out twice.
 *
 * The frame here is a stand-in that prints what it was given, so that each of
 * the three can be asked about on its own.
 */
#[CoversClass(SpecimenExtension::class)]
#[CoversClass(SpecimenNode::class)]
final class SpecimenTagTest extends TestCase
{
    private const string FRAME = <<<'LATTE'
        {define sgExample, string $variant, Latte\Runtime\Html $html, string $markup, string $latte, bool $narrow = false}
        <variant>{$variant}{if $narrow} narrow{/if}</variant>
        <html>{$html}</html>
        <markup>{$markup}</markup>
        <latte>{$latte}</latte>
        {/define}

        LATTE;

    public function testTheHtmlIsWhatTheContentRendersTo(): void
    {
        $output = $this->render(<<<'LATTE'
            <main>
                {specimen 'plain'}
                    <b>{$name}</b>
                {/specimen}
            </main>
            LATTE);

        self::assertStringContainsString('<html>', $output);
        self::assertSame('<b>Ada &amp; Bo</b>', trim($this->between($output, 'html')));
    }

    /** The markup is the same HTML again, as text to read rather than as elements. */
    public function testTheMarkupIsTheSameHtmlEscapedAndSetFlush(): void
    {
        $output = $this->render(<<<'LATTE'
            <main>
                {specimen 'plain'}
                    <ul>
                        <li>{$name}</li>
                    </ul>
                {/specimen}
            </main>
            LATTE);

        self::assertSame(
            "&lt;ul&gt;\n    &lt;li&gt;Ada &amp;amp; Bo&lt;/li&gt;\n&lt;/ul&gt;",
            $this->between($output, 'markup'),
        );
    }

    /**
     * The Latte is the text between the two tags exactly as it is written in
     * the file, comments and all, only moved to the left edge - not a
     * reconstruction of it, which would say what the parser understood rather
     * than what somebody typed.
     */
    public function testTheLatteIsTheSourceBetweenTheTagsAsWritten(): void
    {
        $output = $this->render(<<<'LATTE'
            <main>
                {specimen 'plain'}
                    {* a note to whoever reads the source *}
                    <b n:if="$name !== ''" class="x">{$name|upper}</b>
                {/specimen}
            </main>
            LATTE);

        self::assertSame(
            "{* a note to whoever reads the source *}\n<b n:if=\"\$name !== ''\" class=\"x\">{\$name|upper}</b>",
            $this->text($output, 'latte'),
        );
    }

    /** What the tag is told beside the variant reaches the frame by name. */
    public function testTheArgumentsReachTheFrame(): void
    {
        $output = $this->render("{specimen 'under pressure', narrow: true}<i>x</i>{/specimen}");

        self::assertSame('under pressure narrow', $this->between($output, 'variant'));
    }

    /**
     * A specimen inside a specimen is its own specimen: the inner source is
     * the inner text, and the outer one holds the inner tag as written.
     */
    public function testASpecimenInsideAnotherKeepsItsOwnSource(): void
    {
        $output = $this->render(<<<'LATTE'
            {specimen 'outer'}
                <section>
                    {specimen 'inner'}<em>deep</em>{/specimen}
                </section>
            {/specimen}
            LATTE);

        preg_match_all('#<latte>(.*?)</latte>#s', $output, $sources);

        self::assertSame(
            ['<em>deep</em>', "<section>\n    {specimen 'inner'}<em>deep</em>{/specimen}\n</section>"],
            array_map(
                static fn(string $escaped): string => html_entity_decode($escaped, ENT_QUOTES | ENT_HTML5),
                $sources[1],
            ),
        );
    }

    public function testItNeedsSomethingToCallTheSpecimen(): void
    {
        $this->expectException(CompileException::class);

        $this->render('{specimen}<i>x</i>{/specimen}');
    }

    private function render(string $page): string
    {
        $engine = new Engine();
        $engine->setStrictParsing();
        $engine->addExtension(new SpecimenExtension());
        $engine->setLoader(new StringLoader(['page' => self::FRAME . $page]));

        return $engine->renderToString('page', ['name' => 'Ada & Bo']);
    }

    private function between(string $output, string $element): string
    {
        self::assertSame(1, preg_match(sprintf('#<%1$s>(.*?)</%1$s>#s', $element), $output, $match), $output);

        return $match[1];
    }

    /**
     * What a reader sees in the element: the text, not the way it had to be
     * escaped to get there - Latte writes a brace as an entity in text so that
     * a browser never mistakes it for anything, and that is its business.
     */
    private function text(string $output, string $element): string
    {
        return html_entity_decode($this->between($output, $element), ENT_QUOTES | ENT_HTML5);
    }
}
