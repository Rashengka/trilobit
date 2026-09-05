<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Tenancy;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Content\PathRegistry;
use Trilobit\Core\Contract\Content\ContentRef;
use Trilobit\Core\Domain\Media\MediaFile;
use Trilobit\Core\Domain\Setting\Setting;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Tenancy\TenancyRefused;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * What the filter really does to a query, said against a database rather than
 * against the mapping.
 *
 * Trilobit\Tests\Architecture\EveryTenantedEntityIsScopedTest asks whether
 * every entity is covered; this asks whether being covered means anything.
 * They are two halves of one claim and neither is worth much alone: a rule
 * that counts entities would pass over a filter that produced nonsense, and a
 * working filter proves nothing about the entity somebody adds next week.
 *
 * The first case is the sentence the whole change exists for. Two businesses
 * both have a page at /kontakt; before this, the second one could not save it.
 */
#[CoversNothing]
final class TenantScopedReadingTest extends TestCase
{
    private const string PAGE = 'demo.page';

    private const string CONTACT = 'kontakt';

    private string $schema = '';

    private ?Container $container = null;

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            Database::drop($this->schema);
        }
    }

    public function testTwoBusinessesBothHaveAPageAtTheSameAddress(): void
    {
        $container = $this->emptyDatabase();
        $bikes = Tenants::enter($container, 'Ammonite Bikes', 'bikes.example.com');
        $books = Tenants::create($container, 'Brachiopod Books', 'books.example.org');

        $registry = $container->getByType(PathRegistry::class);
        $registry->register(new ContentRef(self::PAGE, '1'), self::CONTACT, 'Contact the bike shop');

        Tenants::switchTo($container, $books);
        $registry->register(new ContentRef(self::PAGE, '1'), self::CONTACT, 'Contact the bookshop');

        self::assertSame('Contact the bookshop', $this->labelAt($registry, self::CONTACT));

        Tenants::switchTo($container, $bikes);
        self::assertSame('Contact the bike shop', $this->labelAt($registry, self::CONTACT));
    }

    /**
     * The same content identifier in two tenants is two different things, and
     * neither is reachable from the other - so a link built in one tenant can
     * never point into the other's address space.
     */
    public function testTheSameIdentifierInAnotherTenantIsAnotherPiece(): void
    {
        $container = $this->emptyDatabase();
        Tenants::enter($container, 'Ammonite Bikes', 'bikes.example.com');
        $books = Tenants::create($container, 'Brachiopod Books', 'books.example.org');

        $registry = $container->getByType(PathRegistry::class);
        $registry->register(new ContentRef(self::PAGE, '1'), 'about-the-bikes', 'About the bike shop');

        Tenants::switchTo($container, $books);

        self::assertNull($registry->find('about-the-bikes'));
        self::assertNull($registry->canonicalPathOf(new ContentRef(self::PAGE, '1')));
    }

    /** Media is the other tenanted table of Core, and it is scoped the same way. */
    public function testAFileOfOneBusinessIsNotAFileOfTheOther(): void
    {
        $container = $this->emptyDatabase();
        $bikes = Tenants::enter($container, 'Ammonite Bikes');
        $books = Tenants::create($container, 'Brachiopod Books');

        $entityManager = $container->getByType(EntityManagerInterface::class);
        $entityManager->persist(new MediaFile(
            $bikes,
            'media/2026/09/ammonite.jpg',
            'ammonite.jpg',
            'image/jpeg',
            184_320,
            new DateTimeImmutable('2026-09-05T08:00:00+00:00'),
        ));
        $entityManager->flush();

        self::assertCount(1, $entityManager->getRepository(MediaFile::class)->findAll());

        Tenants::switchTo($container, $books);

        self::assertSame([], $entityManager->getRepository(MediaFile::class)->findAll());
    }

    /**
     * Reading a tenanted table before it is settled whose request this is
     * fails, and it fails loudly.
     *
     * A filter that stood down when it had nothing to compare against would be
     * a filter that is absent exactly where it was needed, and the answer -
     * everybody's rows - would look like a working page.
     */
    public function testReadingBeforeATenantIsEnteredIsRefused(): void
    {
        $container = $this->emptyDatabase();

        $this->expectException(TenancyRefused::class);
        $this->expectExceptionMessage('before it was settled which tenant this is');

        $container->getByType(PathRegistry::class)->find(self::CONTACT);
    }

    /**
     * A table that says it is shared is readable without a tenant, which is
     * what makes the refusal above a statement about tenanted tables rather
     * than about the database being unreachable.
     */
    public function testASharedTableIsReadableWithoutATenant(): void
    {
        $container = $this->emptyDatabase();
        $entityManager = $container->getByType(EntityManagerInterface::class);

        $entityManager->persist(new Setting(
            'site.name',
            ['title' => 'Trilobit'],
            new DateTimeImmutable('2026-09-05T08:00:00+00:00'),
        ));
        $entityManager->flush();
        $entityManager->clear();

        self::assertCount(1, $entityManager->getRepository(Setting::class)->findAll());
        self::assertCount(0, $entityManager->getRepository(Tenant::class)->findAll());
    }

    /**
     * Switching tenant empties the object manager, because an object already
     * loaded is handed back without a query - past the filter, which only ever
     * sees SQL. Without that, a process serving one business and then another
     * would answer the second out of the first one's rows.
     */
    public function testSwitchingTenantDoesNotHandBackTheOtherOnesObjects(): void
    {
        $container = $this->emptyDatabase();
        $bikes = Tenants::enter($container, 'Ammonite Bikes');
        $books = Tenants::create($container, 'Brachiopod Books');

        $entityManager = $container->getByType(EntityManagerInterface::class);
        $file = new MediaFile(
            $bikes,
            'media/2026/09/ammonite.jpg',
            'ammonite.jpg',
            'image/jpeg',
            184_320,
            new DateTimeImmutable('2026-09-05T08:00:00+00:00'),
        );
        $entityManager->persist($file);
        $entityManager->flush();

        Tenants::switchTo($container, $books);

        self::assertFalse($entityManager->contains($file));
        self::assertNull($entityManager->find(MediaFile::class, $file->id()));
    }

    private function labelAt(PathRegistry $registry, string $path): string
    {
        $address = $registry->find($path);
        self::assertNotNull($address, 'nothing answers at ' . $path . ' in this tenant');

        return $address->label;
    }

    private function emptyDatabase(): Container
    {
        $this->schema = Database::schemaFor(self::class);
        $this->container = Boot::coreAlone();
        Migrations::run($this->container);

        return $this->container;
    }
}
