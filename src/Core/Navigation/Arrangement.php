<?php

declare(strict_types=1);

namespace Trilobit\Core\Navigation;

/**
 * The top level of one menu, put together out of its contributors and what the
 * business saved about them (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3).
 *
 * **Nothing saved is the default from code.** The contributors stand in the
 * order of their weight, each with all of its entries in its own order.
 *
 * **Something saved is a sequence of places** (Trilobit\Core\Navigation\Composition),
 * and it is filled in three steps:
 *
 * - every place takes the next entry of the contributor it names; a place
 *   whose contributor has no entry left for it - or is not in this build -
 *   stays in the sequence and draws nothing;
 * - a contributor with more entries than places puts the rest right after its
 *   last place, so its own order goes on where it left off;
 * - a contributor the sequence does not name at all comes last, in the default
 *   order. Nothing a module adds later disappears for not having been
 *   arranged yet.
 *
 * A hidden contributor's places are filled like everybody else's and then not
 * drawn, so it keeps where it stood for when it is shown again.
 *
 * **An entry moves past a neighbour of another contributor, and never past one
 * of its own.** Moving is swapping two places, and two places of one
 * contributor are the same place twice: the entries would simply fill them in
 * the same order again. So the move is not offered, and asked for anyway it is
 * refused rather than done as nothing - a press that changed nothing would look
 * exactly like one that worked.
 *
 * What comes out of a move is the whole sequence as it now stands, the places
 * that draw nothing included, and that is what gets saved.
 */
final readonly class Arrangement
{
    /**
     * @param list<string> $places every place, in order, each the key of the contributor it belongs to
     * @param list<string> $hidden
     * @param list<Slot> $slots the places that draw something, in order
     * @param list<Source> $sources every contributor, in the default order
     */
    private function __construct(
        private array $places,
        private array $hidden,
        private array $slots,
        private array $sources,
        private bool $composed,
    ) {}

    /** @param iterable<NavigationContributor> $contributors */
    public static function of(iterable $contributors, ?Composition $composition, string $menu): self
    {
        $sorted = [...$contributors];
        usort(
            $sorted,
            static fn(NavigationContributor $left, NavigationContributor $right): int => [$left->weight(), $left->key()] <=> [$right->weight(), $right->key()],
        );

        $entries = [];
        $labels = [];
        foreach ($sorted as $contributor) {
            $entries[$contributor->key()] = $contributor->entriesOf($menu);
            $labels[$contributor->key()] = $contributor->label();
        }

        $places = $composition instanceof Composition ? $composition->order : [];
        foreach ($sorted as $contributor) {
            $places = self::withTheRestOf($contributor->key(), count($entries[$contributor->key()]), $places);
        }

        $hidden = $composition instanceof Composition ? $composition->hidden : [];

        $slots = [];
        $filled = [];
        foreach ($places as $place => $key) {
            $next = $filled[$key] ?? 0;
            $filled[$key] = $next + 1;

            $entry = $entries[$key][$next] ?? null;
            if ($entry === null || in_array($key, $hidden, true)) {
                continue;
            }

            $slots[] = new Slot($place, $key, $labels[$key] ?? $key, $entry);
        }

        $sources = [];
        foreach ($sorted as $contributor) {
            $sources[] = new Source($contributor->key(), $contributor->label(), in_array($contributor->key(), $hidden, true));
        }

        return new self($places, $hidden, $slots, $sources, $composition instanceof Composition);
    }

    /** @return list<Slot> */
    public function slots(): array
    {
        return $this->slots;
    }

    /** @return list<NavigationEntry> the entries drawn at the top of the menu, in order */
    public function entries(): array
    {
        return array_map(static fn(Slot $slot): NavigationEntry => $slot->entry, $this->slots);
    }

    /** @return list<Source> */
    public function sources(): array
    {
        return $this->sources;
    }

    /** Whether the business saved an arrangement, rather than taking the default. */
    public function isComposed(): bool
    {
        return $this->composed;
    }

    public function canMoveUp(int $slot): bool
    {
        return $this->neighbours($slot - 1, $slot);
    }

    public function canMoveDown(int $slot): bool
    {
        return $this->neighbours($slot, $slot + 1);
    }

    public function movedUp(int $slot): Composition
    {
        return $this->swapped($slot - 1, $slot);
    }

    public function movedDown(int $slot): Composition
    {
        return $this->swapped($slot, $slot + 1);
    }

    public function hiding(string $key): Composition
    {
        return new Composition($this->places, array_values(array_unique([...$this->hidden, $key])));
    }

    public function showing(string $key): Composition
    {
        return new Composition(
            $this->places,
            array_values(array_filter($this->hidden, static fn(string $hidden): bool => $hidden !== $key)),
        );
    }

    /**
     * $places with a place for every entry of $key it has none for, after the
     * last place $key already has, or at the end where it has none.
     *
     * @param list<string> $places
     *
     * @return list<string>
     */
    private static function withTheRestOf(string $key, int $entries, array $places): array
    {
        $taken = array_keys($places, $key, true);
        $missing = $entries - count($taken);
        if ($missing <= 0) {
            return $places;
        }

        $rest = array_fill(0, $missing, $key);
        if ($taken === []) {
            return [...$places, ...$rest];
        }

        array_splice($places, $taken[count($taken) - 1] + 1, 0, $rest);

        return $places;
    }

    /** Whether the two drawn entries next to each other at $upper and $lower belong to different contributors. */
    private function neighbours(int $upper, int $lower): bool
    {
        if (!isset($this->slots[$upper], $this->slots[$lower])) {
            return false;
        }

        return $this->slots[$upper]->contributor !== $this->slots[$lower]->contributor;
    }

    private function swapped(int $upper, int $lower): Composition
    {
        if (!$this->neighbours($upper, $lower)) {
            throw new \LogicException(sprintf(
                'The entries %d and %d of this menu cannot trade places: one of them is not there, or both belong '
                . 'to one contributor, whose own order is kept.',
                $upper,
                $lower,
            ));
        }

        $places = $this->places;
        $upperPlace = $this->slots[$upper]->place;
        $lowerPlace = $this->slots[$lower]->place;
        [$places[$upperPlace], $places[$lowerPlace]] = [$places[$lowerPlace], $places[$upperPlace]];

        return new Composition(array_values($places), $this->hidden);
    }
}
