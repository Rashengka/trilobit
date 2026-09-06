<?php

declare(strict_types=1);

namespace Trilobit\Core\Preference;

/**
 * What one person has chosen, and what the page is therefore drawn with.
 *
 * It holds two things and the difference between them is the whole of decision
 * D8's exception. What was **chosen** is only what somebody deliberately
 * picked; everything else is absent rather than filled in with the default. A
 * preference nobody has an opinion about is what lets a profile take the
 * device's answer when somebody signs in, and it is what keeps a build's own
 * configuration in charge of everybody who has never touched the switch - see
 * Trilobit\Core\Preference\RememberedPreferences.
 *
 * value() therefore answers for every preference this build has, chosen or not,
 * and chosen() answers with the subset that is worth writing down.
 *
 * It is built by Trilobit\Core\Preference\PreferenceCatalogue and by nothing
 * else, because the catalogue is what checks that a value is one this build
 * still knows.
 */
final readonly class Preferences
{
    /**
     * @param non-empty-array<string, Preference> $catalogue every preference this build has, by name
     * @param array<string, string> $chosen what was deliberately chosen, by name
     */
    public function __construct(
        private array $catalogue,
        private array $chosen,
    ) {}

    /** What this preference is set to, whether somebody chose it or not. */
    public function value(string $name): string
    {
        $preference = $this->catalogue[$name] ?? throw new \InvalidArgumentException(sprintf(
            "There is no preference called '%s'; this build has: %s.",
            $name,
            implode(', ', array_keys($this->catalogue)),
        ));

        return $this->chosen[$name] ?? $preference->default;
    }

    /** Whether somebody has an opinion about this one, as opposed to living with the default. */
    public function chose(string $name): bool
    {
        return isset($this->chosen[$name]);
    }

    /**
     * What is worth remembering: the choices, and none of the defaults.
     *
     * @return array<string, string>
     */
    public function chosen(): array
    {
        return $this->chosen;
    }

    /**
     * The page, as attributes on its html element.
     *
     * Every preference is written out, including the ones nobody chose, so that
     * the document states what it was drawn with rather than leaving a reader
     * to work it out from what is missing.
     *
     * @return array<string, string> attribute => value
     */
    public function attributes(): array
    {
        $attributes = [];
        foreach ($this->catalogue as $name => $preference) {
            $attributes[$preference->attribute()] = $this->value($name);
        }

        return $attributes;
    }
}
