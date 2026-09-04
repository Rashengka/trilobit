<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Doctrine;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Trilobit\Core\Domain\Audit\AuditEntry;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Event\EntityChanged;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;

/**
 * Core's own event mechanism, seen end to end: a service dispatches
 * EntityChanged through Trilobit\Core\Event\Dispatcher, and what comes out the
 * other side is a row AuditListener wrote - not a mock of one.
 *
 * The container under test is Core alone: the audit trail is Core's own
 * cross-cutting concern (see the class docblock of Dispatcher) and does not
 * need a module switched on to work.
 */
#[CoversNothing]
final class AuditTrailTest extends TestCase
{
    private string $schema = '';

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            Database::drop($this->schema);
        }
    }

    public function testDispatchingEntityChangedWritesAnAuditEntry(): void
    {
        $this->schema = Database::schemaFor(self::class);
        $container = Boot::coreAlone();
        Migrations::run($container);

        $occurredAt = new DateTimeImmutable('2026-09-04T12:00:00+00:00');
        $event = new EntityChanged(User::class, '1', 'created', $occurredAt);

        $container->getByType(EventDispatcherInterface::class)->dispatch($event);

        $entries = $container->getByType(EntityManagerInterface::class)
            ->getRepository(AuditEntry::class)
            ->findAll();

        self::assertCount(1, $entries);
        self::assertSame(User::class, $entries[0]->entityType());
        self::assertSame('1', $entries[0]->entityId());
        self::assertSame('created', $entries[0]->action());
        self::assertEquals($occurredAt, $entries[0]->occurredAt());
    }
}
