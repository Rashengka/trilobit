<?php

declare(strict_types=1);

namespace Trilobit\Core\Preference;

/**
 * One thing about the way the application is drawn that a person may decide for
 * themselves, and what it may be set to.
 *
 * A preference reaches the page as exactly one attribute on <html>, and it is
 * kept on the device in exactly one cookie. Both names are derived from the
 * preference's own rather than stored beside it: `theme` becomes data-theme and
 * trilobit-theme, `theme-mode` becomes data-theme-mode and trilobit-theme-mode.
 * That is not shorthand. The name is written down in several places that have
 * to agree - the catalogue, the cookie, the profile and the stylesheet - and
 * deriving them from one is that many fewer pairs that can drift apart while
 * everything still renders.
 *
 * The allowed values are held here rather than checked at the edge, because
 * what arrives from a browser and what comes back out of a database are the
 * same kind of thing: a string somebody stored once and nothing has vouched for
 * since. See Trilobit\Core\Preference\PreferenceCatalogue::reconcile().
 */
final readonly class Preference
{
    /** @param non-empty-list<string> $values everything this preference may be set to */
    public function __construct(
        public string $name,
        public string $default,
        public array $values,
    ) {
        if (!in_array($default, $values, true)) {
            throw new \InvalidArgumentException(sprintf(
                "The default of the preference '%s' is '%s', which is not one of: %s.",
                $name,
                $default,
                implode(', ', $values),
            ));
        }
    }

    /** How the choice reaches the page: one attribute on the html element. */
    public function attribute(): string
    {
        return 'data-' . $this->name;
    }

    /**
     * Where the choice is kept on the device: one cookie of its own.
     *
     * One each rather than one holding them all, and the difference is not
     * tidiness. A single cookie would have to be read, changed and written back
     * on every change - so two changes made in the same round trip would both
     * read the old one and the second would drop the first. That is a lost
     * choice with nothing to see: the page looks right, because the switch
     * changed it, and the loss only shows on the next load. A cookie per
     * preference cannot be written that way, so the mistake has nowhere to
     * happen.
     */
    public function cookie(): string
    {
        return 'trilobit-' . $this->name;
    }

    public function accepts(string $value): bool
    {
        return in_array($value, $this->values, true);
    }
}
