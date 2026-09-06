<?php

declare(strict_types=1);

namespace Trilobit\Cms\Domain\Page;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Contract\Content\ContentRef;
use Trilobit\Core\Domain\Tenancy\Tenant;

/**
 * A page somebody wrote, and whether a visitor may see it.
 *
 * **There is no slug here, and that is the decision worth reading.** Where a
 * page answers is a row in Core's register of public addresses
 * (.ai/plans/01e-routing-a-provazani-obsahu.md, R1 and R2), because a page and
 * a product share one address space and only one table can be the one an
 * address is unique in. A `slug` column beside it would be a second answer to
 * the same question, and the two would disagree the first time one of them was
 * written without the other. So this entity knows what it says and the
 * register knows where it says it, and ref() is the whole of the join between
 * them.
 *
 * **The address is claimed while the page is still a draft.** The register
 * holds a row from the moment the page exists, so nothing else can take that
 * address while it is being written; what publishing changes is only whether
 * the page is drawn, and an unpublished one answers 404 rather than being
 * unreachable in some other way. That is also why the state is not derived
 * from the register: taking a page down must not give its address away.
 *
 * A page belongs to a tenant, so every read of this table is scoped by
 * Trilobit\Core\Tenancy\TenantFilter and two businesses may both have one
 * called "About us" at /about-us.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cms_page')]
class Page
{
    /**
     * What the register calls this kind of content, namespaced by the module
     * that owns it.
     *
     * It is here rather than on the service that publishes it because it is
     * the entity's own name in the address space: the writing side, the page
     * that draws it and anything linking to it all have to agree on one
     * string, and one place to read it from is how they do.
     */
    public const string TYPE = 'cms.page';

    public const int MAX_TITLE_LENGTH = 191;

    /** What a search engine shows under the title, and about as much as it will read. */
    public const int MAX_DESCRIPTION_LENGTH = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: PageStatus::class)]
    private PageStatus $status = PageStatus::Draft;

    /** When this page first went live, kept even after it is taken down again. */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    /** The heading and the sentence under it, as the editor typed them. */
    #[ORM\Column(type: Types::TEXT)]
    private string $perex = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';

    /** Empty means the title is good enough, which it usually is. */
    #[ORM\Column(length: self::MAX_TITLE_LENGTH)]
    private string $seoTitle = '';

    #[ORM\Column(length: self::MAX_DESCRIPTION_LENGTH)]
    private string $seoDescription = '';

    public function __construct(
        /** Whose page this is. Two businesses share no page and no address. */
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\Column(length: self::MAX_TITLE_LENGTH)]
        private string $title,
        #[ORM\Column]
        private DateTimeImmutable $updatedAt,
    ) {}

    public function id(): ?int
    {
        return $this->id;
    }

    /**
     * How the register of public addresses refers to this page.
     *
     * A page that has never been saved has no identifier and therefore no
     * reference: there would be nothing for an address to lead to.
     */
    public function ref(): ?ContentRef
    {
        return $this->id === null ? null : new ContentRef(self::TYPE, (string) $this->id);
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function perex(): string
    {
        return $this->perex;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function status(): PageStatus
    {
        return $this->status;
    }

    public function isPublished(): bool
    {
        return $this->status === PageStatus::Published;
    }

    public function publishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /** What the document is titled for a search engine; the page's own title unless something else was said. */
    public function seoTitle(): string
    {
        return $this->seoTitle === '' ? $this->title : $this->seoTitle;
    }

    public function seoDescription(): string
    {
        return $this->seoDescription;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Everything an editor may change about what the page says. */
    public function reviseTo(
        string $title,
        string $perex,
        string $content,
        string $seoTitle,
        string $seoDescription,
        DateTimeImmutable $at,
    ): void {
        $this->title = $title;
        $this->perex = $perex;
        $this->content = $content;
        $this->seoTitle = $seoTitle;
        $this->seoDescription = $seoDescription;
        $this->updatedAt = $at;
    }

    /**
     * Lets visitors see it.
     *
     * The date is set the first time and never moved afterwards, so that a
     * page taken down and put back up keeps the day it was first published -
     * which is what anybody reading the date wants to know.
     */
    public function publish(DateTimeImmutable $at): void
    {
        $this->status = PageStatus::Published;
        $this->publishedAt ??= $at;
        $this->updatedAt = $at;
    }

    /** Takes it out of sight without giving its address away; see the class. */
    public function withdraw(DateTimeImmutable $at): void
    {
        $this->status = PageStatus::Draft;
        $this->updatedAt = $at;
    }
}
