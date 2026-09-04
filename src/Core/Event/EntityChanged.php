<?php

declare(strict_types=1);

namespace Trilobit\Core\Event;

use DateTimeImmutable;

/**
 * Something in the database changed, in the shape the audit trail needs and
 * no other: which entity, which row, what kind of change, and when.
 *
 * This is Core's own event, dispatched and listened to entirely inside
 * Core - see the class docblock of Dispatcher for why a module never gets to
 * either end of it.
 */
final readonly class EntityChanged
{
    public function __construct(
        public string $entityType,
        public string $entityId,
        public string $action,
        public DateTimeImmutable $occurredAt,
    ) {}
}
