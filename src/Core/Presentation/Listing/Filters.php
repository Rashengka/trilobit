<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Listing;

use Doctrine\ORM\QueryBuilder;
use Nette\Application\UI\Presenter;

/**
 * The filters of one place a listing is used - its settings (.ai/plans/15,
 * decision 3) - and the two things done with them: reading what an address
 * asks for, and narrowing a query by what was read.
 *
 * They are written the way a Nette form is, one fluent call per filter:
 *
 * ```php
 * $filters
 *     ->text('title', 'Title', Comparison::Contains)
 *     ->choice('status', 'Status', ['draft' => 'Draft', 'published' => 'Published'], Comparison::Equals);
 * ```
 *
 * **They are the allow-list.** A name the address carries that is not a
 * filter here never becomes a column; it is set aside, and the Reading says
 * so in a sentence (read()). The field a filter narrows is written in the
 * source and checked against the entity's mapping before anything is queried
 * (applyTo()), and what was typed is bound as a parameter and never written
 * into the query (Comparison).
 *
 * **The alias is the builder's.** applyTo() reads the one root alias the
 * builder it is handed really has, rather than a name every listing agrees
 * to use (.ai/plans/15, decision 1) - an agreement is broken by the second
 * listing that was written by somebody else.
 */
final class Filters
{
    /** The prefix of every parameter a filter binds, so that none takes the name of one the listing's own query binds. */
    private const string PARAMETER = 'filter_';

    /** A name the address and the form can both carry: a letter, then letters and digits. */
    private const string NAME = '/^[a-z][a-zA-Z0-9]*$/';

    /** A field of an entity, as PHP names a property. */
    private const string FIELD = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** How much of something the address carried is repeated back in a sentence. */
    private const int QUOTED = 40;

    /** @var array<string, Filter> */
    private array $filters = [];

    /**
     * A filter whose value is typed.
     *
     * @param string|null $field the field it narrows; its name unless said
     */
    public function text(string $name, string $label, Comparison $comparison, ?string $field = null): self
    {
        return $this->add(new Filter($name, $label, $field ?? $name, $comparison));
    }

    /**
     * A filter whose value is chosen from $choices.
     *
     * @param array<int|string, string> $choices what may be chosen, by the value the address carries
     * @param string|null $field the field it narrows; its name unless said
     * @param string $prompt what the control offers for "not narrowed by this"
     */
    public function choice(
        string $name,
        string $label,
        array $choices,
        Comparison $comparison,
        ?string $field = null,
        string $prompt = 'Any',
    ): self {
        if ($choices === []) {
            throw new \LogicException(sprintf('The filter "%s" offers nothing to choose.', $name));
        }

        return $this->add(new Filter($name, $label, $field ?? $name, $comparison, $choices, $prompt));
    }

    /** @return array<string, Filter> by name, in the order they were written */
    public function all(): array
    {
        return $this->filters;
    }

    /**
     * What $asked - the parameters an address carried for the listing, the
     * page taken out - asks the listing to be narrowed by.
     *
     * An empty value is a filter nobody used. Anything else that cannot be
     * used - a name that is not a filter here, a choice that is not offered,
     * several values, more than a field takes - is set aside and said, so that
     * whoever opened the link can tell why the list is not what they expected:
     * dropped without a word, it would be a list that looks filtered and is
     * not.
     *
     * @param array<array-key, mixed> $asked
     */
    public function read(array $asked): Reading
    {
        $values = $setAside = [];
        foreach ($asked as $name => $raw) {
            $name = (string) $name;
            $filter = $this->filters[$name] ?? null;
            if ($filter === null) {
                $setAside[] = sprintf(
                    'This list cannot be filtered by "%s", so that part of the address was set aside.',
                    $this->quoted($name),
                );

                continue;
            }

            if (!is_string($raw) && !is_int($raw)) {
                $setAside[] = sprintf('%s was given more than one value, so the list is not narrowed by it.', $filter->label);

                continue;
            }

            $value = trim((string) $raw);
            if ($value === '') {
                continue;
            }

            if ($filter->isChoice() && !array_key_exists($value, $filter->choices)) {
                $setAside[] = sprintf(
                    '"%s" is not one of the choices of %s, so the list is not narrowed by it.',
                    $this->quoted($value),
                    $filter->label,
                );

                continue;
            }

            if (mb_strlen($value) > Filter::MAX_LENGTH) {
                $setAside[] = sprintf(
                    '%s takes %d characters at most, so the list is not narrowed by it.',
                    $filter->label,
                    Filter::MAX_LENGTH,
                );

                continue;
            }

            $values[$name] = $value;
        }

        return new Reading($values, $setAside);
    }

    /**
     * Narrows $query by what $reading holds, at the alias the query has.
     *
     * Every filter's field is checked against the entity's mapping whether or
     * not it is used this time, so that a field written wrong is refused the
     * first time the listing is drawn rather than the first time somebody
     * filters by it.
     */
    public function applyTo(QueryBuilder $query, Reading $reading): void
    {
        $aliases = $query->getRootAliases();
        $entities = $query->getRootEntities();
        if (count($aliases) !== 1 || count($entities) !== 1) {
            throw new \LogicException(sprintf(
                'Filters narrow a query with one root, and which of %s a filter means is not a guess to make.',
                implode(', ', $aliases),
            ));
        }

        $alias = $aliases[0];
        $metadata = $query->getEntityManager()->getClassMetadata($entities[0]);
        foreach ($this->filters as $filter) {
            if (!$metadata->hasField($filter->field)) {
                throw new \LogicException(sprintf(
                    'The filter "%s" narrows the field "%s", which %s does not map.',
                    $filter->name,
                    $filter->field,
                    $metadata->getName(),
                ));
            }
        }

        foreach ($reading->values as $name => $value) {
            $filter = $this->filters[$name]
                ?? throw new \LogicException(sprintf('"%s" is not a filter of this listing, so it was not read by it.', $name));

            $parameter = self::PARAMETER . $name;
            $query
                ->andWhere($filter->comparison->condition($alias . '.' . $filter->field, $parameter))
                ->setParameter($parameter, $filter->comparison->bound($value));
        }
    }

    private function add(Filter $filter): self
    {
        $reserved = [RequestedPage::PARAMETER, Presenter::SignalKey];
        if (preg_match(self::NAME, $filter->name) !== 1 || in_array($filter->name, $reserved, true)) {
            throw new \LogicException(sprintf(
                '"%s" cannot name a filter: a name is a letter followed by letters and digits, and neither %s.',
                $filter->name,
                implode(' nor ', array_map(static fn(string $name): string => '"' . $name . '"', $reserved)),
            ));
        }

        if (preg_match(self::FIELD, $filter->field) !== 1) {
            throw new \LogicException(sprintf('"%s" is not a field the filter "%s" can narrow.', $filter->field, $filter->name));
        }

        if (isset($this->filters[$filter->name])) {
            throw new \LogicException(sprintf('There is a filter called "%s" already.', $filter->name));
        }

        $this->filters[$filter->name] = $filter;

        return $this;
    }

    /** Something the address carried, as much of it as a sentence repeats back. */
    private function quoted(string $text): string
    {
        return mb_strlen($text) > self::QUOTED ? mb_substr($text, 0, self::QUOTED) . '…' : $text;
    }
}
