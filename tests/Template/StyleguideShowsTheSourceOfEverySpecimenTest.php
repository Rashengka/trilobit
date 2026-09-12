<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Styleguide\StyleguidePage;
use Trilobit\Core\Presentation\Styleguide\StyleguidePages;
use Trilobit\Core\Routing\StyleguideRoutes;

/**
 * Every specimen on a page of the Components group shows, under itself, the
 * HTML it came out as and the Latte it was written in - and both are that
 * specimen's, not a copy somebody typed beside it.
 *
 * The two are held to where they come from rather than merely looked for. The
 * HTML is read back as HTML and has to be the same page as the specimen drawn
 * above it - the same tree, compared by MarkupTree, which lets through only
 * whitespace a browser draws nothing for. It is a tree and not the text
 * because the HTML is laid out afresh before it is shown (Trilobit\Core\
 * Presentation\Styleguide\HtmlSource), so its text is not the specimen's; and
 * it is not the text with every run of whitespace between two tags taken out,
 * as it once was, because that let through the one thing a layout must not do:
 * glue two inline elements together, or push a word inside one away from its
 * tag. The Latte has to be found in the file that
 * draws the page, line after line, indentation aside. A second copy kept by
 * hand would pass both on the day it was written and fail the day the specimen
 * changed and the copy did not - which is the day this is for.
 *
 * The rule is run over the real pages and, in the last cases, over pages made
 * to break it, because a rule that finds nothing reads the same whether it is
 * right or looking in the wrong place.
 */
#[CoversNothing]
final class StyleguideShowsTheSourceOfEverySpecimenTest extends TestCase
{
    /** @return iterable<string, array{StyleguidePage}> */
    public static function componentPages(): iterable
    {
        foreach (StyleguideSpecimens::pages()->pages() as $page) {
            if ($page->group === 'components' && $page->components !== []) {
                yield $page->path() => [$page];
            }
        }
    }

    #[DataProvider('componentPages')]
    public function testEverySpecimenShowsItsOwnHtmlAndLatte(StyleguidePage $page): void
    {
        $path = '/' . StyleguideRoutes::PATH . '/' . $page->path();
        $rendered = StyleguideSpecimens::everyPage()[$path] ?? null;
        self::assertNotNull($rendered, sprintf('%s is listed and was not rendered', $path));

        self::assertSame(
            [],
            $this->problemsOn($rendered, FileSystem::read(StyleguidePages::directory() . '/' . $page->file())),
            sprintf('%s shows a specimen without its own source under it', $path),
        );
    }

    public function testTheRuleReportsASpecimenWithNoSource(): void
    {
        self::assertSame(
            ['plain: no HTML source under it', 'plain: no Latte source under it'],
            $this->problemsOn($this->page('<div data-styleguide-variant="plain"><div class="sg-specimen__stage"><b>x</b></div></div>'), '<b>x</b>'),
        );
    }

    /** The case the rule exists for: the specimen changed and the source beside it did not. */
    public function testTheRuleReportsHtmlThatIsNotTheSpecimen(): void
    {
        self::assertSame(
            ['plain: the HTML under it is not the specimen above it'],
            $this->problemsOn($this->page($this->specimen('<b>new</b>', '<b>old</b>', '<b>new</b>')), '<b>new</b>'),
        );
    }

    public function testTheRuleReportsLatteThatIsNotInThePage(): void
    {
        self::assertSame(
            ['plain: the Latte under it is not written in the page'],
            $this->problemsOn($this->page($this->specimen('<b>x</b>', '<b>x</b>', "{include badge}\n<b>x</b>")), "{block sgPage}\n    <b>x</b>\n{/block}"),
        );
    }

    /**
     * A space between two inline elements is drawn, so HTML that lost it is
     * not the specimen - even though only whitespace between two tags went.
     */
    public function testTheRuleReportsHtmlThatLostASpaceBetweenInlineElements(): void
    {
        self::assertSame(
            ['plain: the HTML under it is not the specimen above it'],
            $this->problemsOn($this->page($this->specimen('<a href="#a">one</a> <a href="#b">two</a>', '<a href="#a">one</a><a href="#b">two</a>', '<b>x</b>')), '<b>x</b>'),
        );
    }

    /** Indentation is where the specimen sits in the file, not part of it, and counts for nothing either way. */
    public function testTheRuleLeavesSourceThatOnlyDiffersInIndentation(): void
    {
        self::assertSame(
            [],
            $this->problemsOn($this->page($this->specimen("<p>\n  <b>x</b>\n</p>", "<p>\n<b>x</b></p>", "<p>\n    <b>x</b>\n</p>")), "{block sgPage}\n        <p>\n            <b>x</b>\n        </p>\n{/block}"),
        );
    }

    /**
     * What is wrong with the specimens of one rendered page, given the source
     * of the file that drew it.
     *
     * @return list<string>
     */
    private function problemsOn(HTMLDocument $page, string $file): array
    {
        $problems = [];
        foreach ($page->querySelectorAll(sprintf('[%s]', StyleguideSpecimens::VARIANT)) as $specimen) {
            $variant = $specimen->getAttribute(StyleguideSpecimens::VARIANT) ?? '';
            $stage = $this->stageOf($specimen);
            $html = $specimen->querySelector('code.language-markup');
            $latte = $specimen->querySelector('code.language-latte');

            if (!$html instanceof Element) {
                $problems[] = $variant . ': no HTML source under it';
            } elseif (!$stage instanceof Element || MarkupTree::of($stage->innerHTML) !== MarkupTree::of($html->textContent ?? '')) {
                $problems[] = $variant . ': the HTML under it is not the specimen above it';
            }

            if (!$latte instanceof Element) {
                $problems[] = $variant . ': no Latte source under it';
            } elseif (!str_contains($this->lines($file), $this->lines($latte->textContent ?? ''))) {
                $problems[] = $variant . ': the Latte under it is not written in the page';
            }
        }

        return $problems;
    }

    /**
     * What the specimen is drawn on: its own stage, and not one belonging to a
     * specimen shown inside it. The DOM here has no :scope, so the children
     * are asked one by one.
     */
    private function stageOf(Element $specimen): ?Element
    {
        for ($child = $specimen->firstElementChild; $child instanceof Element; $child = $child->nextElementSibling) {
            $classes = preg_split('/\s+/', $child->getAttribute('class') ?? '', -1, PREG_SPLIT_NO_EMPTY);
            if (in_array('sg-specimen__stage', $classes === false ? [] : $classes, true)) {
                return $child;
            }
        }

        return null;
    }

    /** Source with every line's indentation taken off, so that where it sits in the file does not count. */
    private function lines(string $source): string
    {
        return implode("\n", array_map(ltrim(...), explode("\n", trim($source))));
    }

    private function specimen(string $stage, string $html, string $latte): string
    {
        return sprintf(
            '<div data-styleguide-variant="plain"><div class="sg-specimen__stage">%s</div>'
                . '<pre><code class="language-markup">%s</code></pre><pre><code class="language-latte">%s</code></pre></div>',
            $stage,
            htmlspecialchars($html),
            htmlspecialchars($latte),
        );
    }

    private function page(string $body): HTMLDocument
    {
        return HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $body . '</body></html>', LIBXML_NOERROR);
    }
}
