<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Event;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\Audit\AuditEntry;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Event\AuditListener;
use Trilobit\Core\Event\EntityChanged;

#[CoversClass(AuditListener::class)]
final class AuditListenerTest extends TestCase
{
    public function testTheEventBecomesAPersistedAuditEntry(): void
    {
        $occurredAt = new DateTimeImmutable('2026-09-04T12:00:00+00:00');
        $event = new EntityChanged(User::class, '1', 'created', $occurredAt);

        $entityManager = self::createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static fn(mixed $entry): bool => $entry instanceof AuditEntry
                && $entry->entityType() === $event->entityType
                && $entry->entityId() === $event->entityId
                && $entry->action() === $event->action
                && $entry->occurredAt() === $event->occurredAt));
        $entityManager->expects(self::once())->method('flush');

        $listener = new AuditListener($entityManager);
        $listener($event);
    }
}
