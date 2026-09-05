<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\Setting;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Tenancy\Shared;

/**
 * One thing about this installation that an administrator may change, kept
 * where a deployment can change it without touching a file.
 *
 * It sits beside .env and config/local.neon rather than replacing either, and
 * the split is by who decides. What a machine needs in order to start - a
 * database host, a debug flag - belongs in the environment, because a value
 * kept in the database cannot be read before the database can be reached. What
 * a person running the shop decides belongs here.
 *
 * The value is JSON, so a setting is a value of whatever shape it needs and not
 * a string somebody has to parse. The column carrying the name is quoted
 * because `key` is a reserved word on the tested server; the mapping says so
 * rather than the migration, so that the generated migration stays generated.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_setting')]
#[Shared(because: 'what a setting says is true of the installation, not of one of the businesses running on it')]
class Setting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(name: '`key`', length: 191, unique: true)]
        private string $key,
        #[ORM\Column(type: Types::JSON)]
        private mixed $value,
        #[ORM\Column]
        private DateTimeImmutable $updatedAt,
    ) {}

    public function id(): ?int
    {
        return $this->id;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function changeTo(mixed $value, DateTimeImmutable $at): void
    {
        $this->value = $value;
        $this->updatedAt = $at;
    }
}
