<?php

declare(strict_types=1);

namespace Trilobit\Core\Preference;

/**
 * What one person has chosen, what the page they are on insists on instead, and
 * what the page is therefore drawn with.
 *
 * What was **chosen** is only what somebody deliberately picked; everything else
 * is absent rather than filled in with the default, and that difference is the
 * whole of decision D8's exception. A preference nobody has an opinion about is
 * what lets a profile take the device's answer when somebody signs in, and it is
 * what keeps a build's own configuration in charge of everybody who has never
 * touched the switch - see Trilobit\Core\Preference\RememberedPreferences.
 *
 * What is **overruled** is what one page has to be drawn at whatever anybody
 * prefers: a report with a column for every day of a month is unusable in a
 * reading column and cannot wait for somebody to remember to switch
 * (.ai/plans/09-chrome-a-sirka-obsahu.md, L4). It wins over the choice, and it
 * is the exception rather than the rule, which is the way round that leaves the
 * person's own setting in charge of the pages nobody had to make an exception
 * for.
 *
 * The three are kept apart on purpose, and it is the second thing that goes
 * wrong rather than the first. value() is what this page is drawn with,
 * preferred() is what the person's setting says whatever this page does, and
 * chosen() - the only one anything writes down - never carries what a page
 * insisted on. Were they one, a report drawn at the full width would turn into
 * the person having asked for the full width everywhere, at the moment they next
 * touched any control.
 *
 * It is built by Trilobit\Core\Preference\PreferenceCatalogue and by itself out
 * of one it built, because the catalogue is what checks that a value is one this
 * build still knows.
 */
final readonly class Preferences
{
    /**
     * @param non-empty-array<string, Preference> $catalogue every preference this build has, by name
     * @param array<string, string> $chosen what was deliberately chosen, by name
     * @param array<string, string> $overruled what this page insists on, by name
     */
    public function __construct(
        private array $catalogue,
        private array $chosen,
        private array $overruled = [],
    ) {}

    /** What this page is drawn with: what it insists on, else what was chosen, else what this build starts in. */
    public function value(string $name): string
    {
        return $this->overruled[$name] ?? $this->preferred($name);
    }

    /**
     * What the person's own setting is, whatever this particular page is drawn
     * at.
     *
     * This is what a control shows. A switch that showed value() would report
     * the exception as the setting on every page that makes one, and the next
     * thing anybody clicked would save it.
     */
    public function preferred(string $name): string
    {
        return $this->chosen[$name] ?? $this->preference($name)->default;
    }

    /** Whether somebody has an opinion about this one, as opposed to living with the default. */
    public function chose(string $name): bool
    {
        return isset($this->chosen[$name]);
    }

    /** Whether this page is drawn at something other than what the person reading it prefers. */
    public function isOverruled(string $name): bool
    {
        return isset($this->overruled[$name]);
    }

    /**
     * The same preferences, as one page insists on being drawn.
     *
     * A value this build does not have throws, and that is the opposite of what
     * a stored one gets. A cookie naming a deleted theme is data written some
     * time ago and is dropped without a word
     * (Trilobit\Core\Preference\PreferenceCatalogue::reconcile()); this argument
     * is a line of code in this checkout, so the only useful answer is to stop.
     */
    public function overruledWith(string $name, string $value): self
    {
        $preference = $this->preference($name);

        if (!$preference->accepts($value)) {
            throw new \InvalidArgumentException(sprintf(
                "A page asked to be drawn with '%s' set to '%s'; this build accepts: %s.",
                $name,
                $value,
                implode(', ', $preference->values),
            ));
        }

        return new self($this->catalogue, $this->chosen, [...$this->overruled, $name => $value]);
    }

    /**
     * What is worth remembering: the choices, and neither the defaults nor
     * anything a page insisted on.
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
     * to work it out from what is missing. What a page insists on is written out
     * here and nowhere else, which is what keeps it a fact about one rendering.
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

    private function preference(string $name): Preference
    {
        return $this->catalogue[$name] ?? throw new \InvalidArgumentException(sprintf(
            "There is no preference called '%s'; this build has: %s.",
            $name,
            implode(', ', array_keys($this->catalogue)),
        ));
    }
}
