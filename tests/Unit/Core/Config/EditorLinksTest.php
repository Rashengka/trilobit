<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Config\EditorLinks;
use Trilobit\Core\Config\Environment;

/**
 * What a stack trace's paths are rewritten to before an editor is asked to
 * open one.
 *
 * **The rewriting is asserted the way Tracy does it, with strtr(), and that is
 * the whole reason this file exists.** Tracy\Helpers::editorUri() calls
 * `strtr($file, Debugger::$editorMapping)`, which replaces the key wherever it
 * occurs rather than only at the beginning of the path. Asserting the mapping
 * array on its own would say nothing about that: the first version of this
 * mapping was keyed on the root without its separator, looked perfectly
 * correct as an array, and turned
 * `/app/vendor/nette/application/src/...` into a path with the middle of
 * `application` rewritten - a link to a file that has never existed anywhere,
 * which opens nothing and says nothing. It was found by reading a rendered
 * page; this is so that it does not have to be found that way twice.
 */
#[CoversClass(EditorLinks::class)]
final class EditorLinksTest extends TestCase
{
    /** The shape of the case that was actually broken: the root is a prefix of a directory name below it. */
    private const string INSIDE_THE_CONTAINER = '/app';

    private const string ON_THE_HOST = '/somewhere/else/trilobit';

    public function testAPathBelowTheRootIsRewrittenOntoTheEditorsMachine(): void
    {
        self::assertSame(
            self::ON_THE_HOST . '/src/Core/Bootstrap.php',
            $this->asTracyWouldRewriteIt(self::INSIDE_THE_CONTAINER . '/src/Core/Bootstrap.php'),
        );
    }

    /**
     * The one that was wrong. `/app` occurs twice in this path - once as the
     * root and once inside `application` - and only the first of them is a
     * root.
     */
    public function testADirectoryWhoseNameBeginsWithTheRootIsLeftAlone(): void
    {
        self::assertSame(
            self::ON_THE_HOST . '/vendor/nette/application/src/Application/Application.php',
            $this->asTracyWouldRewriteIt(
                self::INSIDE_THE_CONTAINER . '/vendor/nette/application/src/Application/Application.php',
            ),
        );
    }

    /** A trailing separator on either side is not a second one in the result. */
    public function testItDoesNotMatterWhetherEitherSideWasWrittenWithASeparator(): void
    {
        self::assertSame(
            self::ON_THE_HOST . '/src/X.php',
            $this->asTracyWouldRewriteIt(
                self::INSIDE_THE_CONTAINER . '/src/X.php',
                self::ON_THE_HOST . '/',
                self::INSIDE_THE_CONTAINER . '/',
            ),
        );
    }

    /**
     * Saying nothing rewrites nothing. It is the answer a public repository has
     * to be able to ship, and it is right on every machine where the editor
     * sees the same paths the application does.
     */
    public function testADeploymentThatSaidNothingHasNothingRewritten(): void
    {
        self::assertSame([], EditorLinks::mapping(Environment::fromValues([]), self::INSIDE_THE_CONTAINER));
        self::assertSame(
            self::INSIDE_THE_CONTAINER . '/src/Core/Bootstrap.php',
            strtr(
                self::INSIDE_THE_CONTAINER . '/src/Core/Bootstrap.php',
                EditorLinks::mapping(Environment::fromValues([]), self::INSIDE_THE_CONTAINER),
            ),
        );
    }

    public function testThePatternIsTheJetBrainsSchemeUnlessTheDeploymentNamesAnother(): void
    {
        self::assertSame(EditorLinks::EDITOR, EditorLinks::pattern(Environment::fromValues([])));
        self::assertSame(
            'vscode://file/%file:%line',
            EditorLinks::pattern(Environment::fromValues(['TRILOBIT_EDITOR' => 'vscode://file/%file:%line'])),
        );
    }

    /** Exactly what Tracy\Helpers::editorUri() does with the mapping, and nothing else. */
    private function asTracyWouldRewriteIt(
        string $file,
        string $onTheHost = self::ON_THE_HOST,
        string $root = self::INSIDE_THE_CONTAINER,
    ): string {
        return strtr(
            $file,
            EditorLinks::mapping(Environment::fromValues(['TRILOBIT_EDITOR_ROOT' => $onTheHost]), $root),
        );
    }
}
