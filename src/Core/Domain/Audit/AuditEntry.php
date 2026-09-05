<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\Audit;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Event\EntityChanged;
use Trilobit\Core\Tenancy\Shared;

/**
 * One row of the audit trail: what changed, and when.
 *
 * It exists so the answer to "what changed" survives the request that caused
 * it, which is also why it stores the entity as a type and an identifier
 * rather than an association - the entity an entry describes may since have
 * been deleted, or may belong to a module that is switched off by the time
 * somebody reads the trail back.
 *
 * Written exclusively by Trilobit\Core\Event\AuditListener, in response to
 * EntityChanged; nothing else constructs one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_audit_entry')]
#[Shared(because: 'the trail records what the installation did, including what happened before any tenant was entered; giving it the dimension is its own change and is not this one')]
class AuditEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 255)]
        private string $entityType,
        #[ORM\Column(length: 255)]
        private string $entityId,
        #[ORM\Column(length: 32)]
        private string $action,
        #[ORM\Column]
        private DateTimeImmutable $occurredAt,
    ) {}

    public static function of(EntityChanged $event): self
    {
        return new self($event->entityType, $event->entityId, $event->action, $event->occurredAt);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function entityType(): string
    {
        return $this->entityType;
    }

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
