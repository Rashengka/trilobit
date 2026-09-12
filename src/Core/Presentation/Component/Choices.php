<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Component;

/**
 * A set of answers of which one is on, for c-button-group to draw as radio
 * buttons.
 *
 * It is an object rather than three parameters of the component because the
 * three only work together, and each way of getting them wrong draws a group
 * that looks like one that works: radios without a name are not one choice (a
 * browser lets every one of them be checked at once), and an answer said to be
 * on that is not one of the answers leaves none of them on. A template cannot
 * refuse anything; a constructor can, so the mistake stops the page instead.
 */
final readonly class Choices
{
    /**
     * @param string $name the name the answers are one choice under, as a form sends it
     * @param array<int|string, string> $options value => the words on it
     * @param string|null $checked the value that is on, or null for none yet
     */
    public function __construct(
        public string $name,
        public array $options,
        public ?string $checked = null,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException(
                'A set of choices needs a name, or a browser lets every one of them be checked at once.',
            );
        }

        if ($options === []) {
            throw new \InvalidArgumentException(sprintf('The choice "%s" has no answers to choose from.', $name));
        }

        if ($checked !== null && !array_key_exists($checked, $options)) {
            throw new \InvalidArgumentException(sprintf(
                'The choice "%s" has no answer "%s" to be on, so none of its answers would be.',
                $name,
                $checked,
            ));
        }
    }

    /**
     * A value PHP turned into a number as an array key ('2' => 'Two') is still
     * the answer that was asked for, so the two are compared as strings.
     */
    public function isChecked(int|string $value): bool
    {
        return $this->checked !== null && (string) $value === $this->checked;
    }
}
