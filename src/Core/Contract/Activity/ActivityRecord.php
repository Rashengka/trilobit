<?php

declare(strict_types=1);

namespace Trilobit\Core\Contract\Activity;

use DateTimeImmutable;
use Trilobit\Core\Contract\Party\PartyRef;

/**
 * One thing that happened to a person, as a module hands it to
 * ActivityRecorder without knowing who, if anybody, keeps a timeline of it.
 *
 * `type` is a dotted string ('shop.order.placed') rather than a class, on
 * purpose: a recorder that does not recognise it has to ignore it rather than
 * fail, because PartyRef may point at a kind of person or event that did not
 * exist when the recorder was written. `payload` is the one untyped place in
 * this design - see .ai/plans/01a-komunikace-modulu.md §3.1 for why a typed
 * class per activity kind was rejected - so it is restricted to scalars a
 * recorder can store without knowing their shape.
 */
final readonly class ActivityRecord
{
    /** @param array<string, scalar|null> $payload */
    public function __construct(
        public PartyRef $subject,
        public string $type,
        public string $title,
        public DateTimeImmutable $occurredAt,
        public ?SourceRef $source = null,
        public array $payload = [],
    ) {}
}
