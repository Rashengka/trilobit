<?php

declare(strict_types=1);

namespace Trilobit\Core\Event;

use Doctrine\ORM\EntityManagerInterface;
use Trilobit\Core\Domain\Audit\AuditEntry;

/**
 * Turns EntityChanged into a row of the audit trail.
 *
 * Tagged Trilobit\Core\DI\CoreExtension::TAG_EVENT_LISTENER like any other
 * listener; what makes this one different is that it is registered by Core
 * itself rather than by a module, because the audit trail is one of the
 * cross-cutting concerns Core's own dispatcher exists for - see the class
 * docblock of Dispatcher.
 */
final readonly class AuditListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(EntityChanged $event): void
    {
        $this->entityManager->persist(AuditEntry::of($event));
        $this->entityManager->flush();
    }
}
