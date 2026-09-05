<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\Tenancy;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Tenancy\Shared;

/**
 * One of the businesses this installation runs, and the thing almost every
 * other row belongs to.
 *
 * Tenancy is a dimension rather than a feature: it is not added to a table
 * later, it is the column a table is unique within. Two firms both have a page
 * at /kontakt, both have media called logo.png, and neither may ever see a row
 * of the other's - so the tenant has to be settled before anything is read,
 * and everything tenanted has to carry it. See
 * Trilobit\Core\Tenancy\TenantFilter for the half of that a machine enforces.
 *
 * The tenant is not itself tenanted. It is what tenancy is measured against,
 * so a filter over it would have to compare a row's own identifier, and the
 * question "may this person see this tenant" is a question about permissions
 * rather than about the dimension.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_tenant')]
#[Shared(because: 'it is what tenancy is measured against; a filter over it would compare a row with itself, and who may see which tenant is a question about permissions rather than about the dimension')]
class Tenant
{
    public const int MAX_NAME_LENGTH = 191;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        /** What this business is called, for whoever administers the installation. */
        #[ORM\Column(length: self::MAX_NAME_LENGTH)]
        private string $name,
        #[ORM\Column]
        private DateTimeImmutable $createdAt,
        /**
         * Which of the three ways this tenant's addresses say what language
         * they are in. One column, so that no combination of two of them can
         * be written down at all; see Trilobit\Core\Domain\Tenancy\
         * LanguageStrategy.
         */
        #[ORM\Column(length: 16, enumType: LanguageStrategy::class)]
        private LanguageStrategy $languageStrategy = LanguageStrategy::Slug,
    ) {}

    public function id(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function languageStrategy(): LanguageStrategy
    {
        return $this->languageStrategy;
    }

    public function useLanguageStrategy(LanguageStrategy $strategy): void
    {
        $this->languageStrategy = $strategy;
    }
}
