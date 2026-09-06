<?php

declare(strict_types=1);

namespace Trilobit\Core\Preference;

use Trilobit\Core\Presentation\Design\DesignSystem;

/**
 * Which preferences this build has, and the one place a stored value is
 * checked against them.
 *
 * There are two of them today and the shape is the point: adding a third is one
 * entry here, one attribute selector in a theme file, and one more control -
 * not a migration and not a column. That is a deliberate middle position
 * between the two things this could have been. A column called `theme` would
 * have to be joined by a column called `content-width` (see
 * .ai/plans/09-chrome-a-sirka-obsahu.md, L4), one called `density` and one for
 * the narrowed menu, each with a migration behind it; a configurable settings
 * system would be a feature nobody asked for. What is here is a named list.
 *
 * The list is not configurable and should not become so. A preference is
 * something the stylesheet has a rule for, so inventing one at run time would
 * produce an attribute nothing draws.
 *
 * reconcile() is the whole of the validation and every stored value goes
 * through it - the cookie, the profile, and what a browser posts. A value this
 * build no longer has is dropped rather than honoured, which is what stops a
 * renamed or deleted theme from rendering a page with no tokens at all.
 */
final readonly class PreferenceCatalogue
{
    public const string THEME = 'theme';

    public const string THEME_MODE = 'theme-mode';

    /**
     * Light and dark are variants inside a theme rather than themes of their
     * own (decision D7), and `system` is the third answer: leave it to the
     * operating system. It is the default because a visitor who has said
     * nothing has said nothing about this either.
     *
     * @var non-empty-list<string>
     */
    private const array THEME_MODES = ['system', 'light', 'dark'];

    /** @param non-empty-array<string, Preference> $preferences by name */
    private function __construct(private array $preferences) {}

    public static function of(DesignSystem $design): self
    {
        return new self([
            self::THEME => new Preference(self::THEME, $design->defaultTheme, $design->themes),
            self::THEME_MODE => new Preference(self::THEME_MODE, self::THEME_MODES[0], self::THEME_MODES),
        ]);
    }

    /** @return non-empty-array<string, Preference> by name */
    public function all(): array
    {
        return $this->preferences;
    }

    /** @return non-empty-list<string> */
    public function names(): array
    {
        return array_keys($this->preferences);
    }

    public function preference(string $name): Preference
    {
        return $this->preferences[$name] ?? throw new \InvalidArgumentException(sprintf(
            "There is no preference called '%s'; this build has: %s.",
            $name,
            implode(', ', $this->names()),
        ));
    }

    /** Whether $name is a preference of this build and $value one of its answers. */
    public function accepts(string $name, string $value): bool
    {
        return isset($this->preferences[$name]) && $this->preferences[$name]->accepts($value);
    }

    /**
     * What somebody stored, as this build is able to honour it.
     *
     * Anything unrecognised is left out rather than refused: what arrives here
     * is a cookie or a row written some time ago, and a build that dropped a
     * theme between then and now would otherwise turn every one of that
     * theme's visitors into an error page. Left out, the preference simply
     * falls back to what this build says - which is the answer somebody who
     * had never chosen would get.
     *
     * @param array<array-key, mixed> $stored
     */
    public function reconcile(array $stored): Preferences
    {
        $chosen = [];
        foreach ($this->preferences as $name => $preference) {
            $value = $stored[$name] ?? null;
            if (is_string($value) && $preference->accepts($value)) {
                $chosen[$name] = $value;
            }
        }

        return new Preferences($this->preferences, $chosen);
    }
}
