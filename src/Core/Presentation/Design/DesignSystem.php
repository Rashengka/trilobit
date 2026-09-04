<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Design;

use Nette\Utils\Finder;

/**
 * Which themes this installation has, and which one it starts in.
 *
 * The list is read from assets/themes/ rather than written down beside it. A
 * theme is one file of token values (see assets/themes/atrium.css), so a
 * configured list would be a second place saying the same thing, and the two
 * would part company the first time somebody added a file and nothing happened.
 *
 * The default is configuration, because that is a decision about this
 * deployment rather than about the code; it has to name a theme that is
 * actually here, and saying so on startup is cheaper than a page that renders
 * with no tokens at all.
 */
final readonly class DesignSystem
{
    /** Under the project root. Themes live beside base.css, not inside src/. */
    public const string DIRECTORY = 'assets/themes';

    /** @param non-empty-list<string> $themes sorted by name */
    private function __construct(
        public string $defaultTheme,
        public array $themes,
    ) {}

    public static function of(string $rootDirectory, string $defaultTheme): self
    {
        $directory = $rootDirectory . '/' . self::DIRECTORY;

        $themes = [];
        foreach (Finder::findFiles('*.css')->in($directory) as $file) {
            $themes[] = pathinfo((string) $file, PATHINFO_FILENAME);
        }

        sort($themes);

        if ($themes === []) {
            throw new \RuntimeException(sprintf(
                'There is no theme in %s, so nothing declares the tokens assets/base.css is written against.',
                $directory,
            ));
        }

        if (!in_array($defaultTheme, $themes, true)) {
            throw new \RuntimeException(sprintf(
                "The default theme is '%s' and %s holds only: %s.",
                $defaultTheme,
                $directory,
                implode(', ', $themes),
            ));
        }

        return new self($defaultTheme, $themes);
    }
}
