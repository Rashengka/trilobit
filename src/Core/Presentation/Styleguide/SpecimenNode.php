<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Styleguide;

use Latte\CompileException;
use Latte\Compiler\Nodes\AreaNode;
use Latte\Compiler\Nodes\Php\Expression\ArrayNode;
use Latte\Compiler\Nodes\StatementNode;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;
use Latte\Compiler\TemplateParser;
use Latte\Compiler\Token;
use Latte\Compiler\TokenStream;

/**
 * `{specimen 'variant'} ... {/specimen}`: one specimen of the style guide,
 * drawn once and shown three ways - as itself, as the HTML it came out as, and
 * as the Latte it was written in.
 *
 * **Nothing is written twice.** The HTML is the output of the very render the
 * specimen is drawn by, caught on its way to the page; the specimen is drawn
 * from it as it came, and the copy shown as code is laid out afresh by
 * HtmlSource, which moves whitespace and nothing a browser draws. The Latte is the text of
 * the template between the two tags, taken at compile time out of the tokens
 * the parser has already read - so it is exactly what the file says, comments
 * and all, rather than what a second copy or a reconstruction from the parsed
 * tree would say. A file that changes is compiled again, and its source moves
 * with it.
 *
 * What the three are drawn in is not decided here but by the block named
 * below, which the page imports from the style guide's furniture: this node
 * hands it the arguments written on the tag and adds the three. A page that
 * has not imported the block fails to render, loudly, rather than drawing a
 * specimen with nothing round it.
 */
final class SpecimenNode extends StatementNode
{
    /** The block the specimen is drawn in: see templates/furniture.latte. */
    public const string FRAME = 'sgExample';

    public ArrayNode $args;

    public AreaNode $content;

    /** The Latte between the two tags, as written and set flush left. */
    public string $source = '';

    /** @return \Generator<int, ?list<string>, array{AreaNode, ?Tag}, self> */
    public static function create(Tag $tag, TemplateParser $parser): \Generator
    {
        $tag->expectArguments('the variant the specimen is shown under');
        $tag->outputMode = Tag::OutputRemoveIndentation;

        $node = $tag->node = new self();
        $node->args = $tag->parser->parseArguments();

        [$node->content, $end] = yield;
        if (!$end instanceof Tag) {
            throw new CompileException(sprintf('Missing {/%s}.', $tag->name), $tag->position);
        }

        $node->source = SourceText::dedent(self::written($parser->getStream(), $tag->end->offset, $end->position->offset));

        return $node;
    }

    public function print(PrintContext $context): string
    {
        return $context->format(
            <<<'XX'
                ob_start(fn() => '') %line;
                try {
                    %node
                } finally {
                    $ʟ_specimen = (string) ob_get_clean();
                }
                $this->renderBlock(%dump, %node + ['html' => new LR\Html($ʟ_specimen), 'markup' => %raw::format($ʟ_specimen), 'latte' => %dump], %dump) %line;

                XX,
            $this->position,
            $this->content,
            self::FRAME,
            $this->args,
            '\\' . HtmlSource::class,
            $this->source,
            $context->getEscaper()->export(),
            $this->position,
        );
    }

    public function &getIterator(): \Generator
    {
        yield $this->args;
        yield $this->content;
    }

    /**
     * The text of every token between two offsets of the template.
     *
     * The parser keeps every token it has read, and the tokens cover the
     * template without a gap, so reading back from the closing tag to the end
     * of the opening one gives the source between them byte for byte.
     */
    private static function written(TokenStream $stream, int $from, int $to): string
    {
        $text = '';
        for ($back = -1; ($token = $stream->tryPeek($back)) instanceof Token; $back--) {
            // Only the token closing the stream has no position, and it is
            // never read back to from a closing tag.
            $offset = $token->position->offset ?? PHP_INT_MAX;
            if ($offset < $from) {
                break;
            }

            if ($offset < $to) {
                $text = $token->text . $text;
            }
        }

        return $text;
    }
}
