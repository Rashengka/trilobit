<?php

declare(strict_types=1);

namespace Trilobit\Core\Navigation;

/**
 * What a business saved about one of its menus: the order its contributors
 * stand in, and which of them are not drawn at all
 * (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3, decided 2026-09-13).
 *
 * **The order is a sequence of places, each naming a contributor, and never
 * an entry.** The entry standing in a place is the next one of that
 * contributor in the contributor's own order - so three places for the
 * categories and one for the pages between them is "an article in the middle
 * and the categories around it", and the order inside one contributor cannot
 * be saved wrong, because it is not saved here at all. It stays where it
 * already is: with the contributor, which for an arranged menu is the
 * position of an entry among its siblings. How the places are filled is
 * Trilobit\Core\Navigation\Arrangement.
 *
 * The names are the contributors' keys, and a name nobody answers to - a
 * module switched off - is kept rather than dropped, so that arranging
 * something else does not throw away where that module will come back to.
 */
final readonly class Composition
{
    /**
     * @param list<string> $order one contributor key per place, a key repeated for each place it has
     * @param list<string> $hidden the keys of the contributors not drawn
     */
    public function __construct(
        public array $order = [],
        public array $hidden = [],
    ) {}

    /**
     * Reads back what toArray() wrote.
     *
     * Only the application writes an arrangement, so one of any other shape is
     * a mistake somebody has to see, and it is refused. Reading it as nothing
     * arranged would draw the default and look exactly like a business that
     * chose the default.
     *
     * @param array<mixed> $stored
     */
    public static function fromArray(array $stored): self
    {
        return new self(self::namesIn($stored, 'order'), self::namesIn($stored, 'hidden'));
    }

    /** @return array{order: list<string>, hidden: list<string>} */
    public function toArray(): array
    {
        return ['order' => $this->order, 'hidden' => $this->hidden];
    }

    /**
     * @param array<mixed> $stored
     *
     * @return list<string>
     */
    private static function namesIn(array $stored, string $field): array
    {
        $value = $stored[$field] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException(sprintf(
                'A saved arrangement keeps its %s as a list of contributor keys, and this one holds %s.',
                $field,
                get_debug_type($value),
            ));
        }

        $names = [];
        foreach ($value as $name) {
            if (!is_string($name)) {
                throw new \InvalidArgumentException(sprintf(
                    'A saved arrangement names its contributors by key, and its %s holds %s.',
                    $field,
                    get_debug_type($name),
                ));
            }

            $names[] = $name;
        }

        return $names;
    }
}
