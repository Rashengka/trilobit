<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Doctrine;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\Media\MediaFile;
use Trilobit\Core\Domain\Setting\Setting;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;

/**
 * The four entities Core owns, written to a database that was built by running
 * the migrations and read back out of it.
 *
 * The point is the round trip rather than the objects. A mapping that is wrong
 * about a column type, a migration that is missing a table, a JSON column that
 * comes back as a string - none of those show up in a unit test, and all three
 * show up here on the first read. The schema is made the way a customer's is
 * made, by executing the migrations, so this is also what says the generated
 * migration is complete.
 *
 * The entity manager is cleared between writing and reading, because a read
 * served out of the identity map would be the object that was just written and
 * would prove nothing about what the database holds.
 */
#[CoversNothing]
final class CoreEntitiesTest extends TestCase
{
    private string $schema = '';

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            Database::drop($this->schema);
        }
    }

    public function testAnAccountKeepsItsRolesAcrossARoundTrip(): void
    {
        $entityManager = $this->emptyDatabase();

        $account = new User(
            'alice@example.com',
            'not a real hash',
            'Alice Ammonite',
            new DateTimeImmutable('2026-09-04T08:00:00+00:00'),
        );
        $account->grant(new Role('administrator', 'Administrator', ['administration']));
        $account->grant(new Role('editor', 'Editor', ['content.write', 'administration']));

        $entityManager->persist($account);
        $entityManager->flush();
        $entityManager->clear();

        $read = $entityManager->getRepository(User::class)->findOneBy(['email' => 'alice@example.com']);

        self::assertInstanceOf(User::class, $read);
        self::assertSame('Alice Ammonite', $read->name());
        self::assertTrue($read->isActive());
        self::assertSame(['administrator', 'editor'], $read->roleCodes());
        self::assertSame(['administration', 'content.write'], $read->permissions());
    }

    /**
     * The column is called `key`, which is a reserved word on this server, so
     * this is also the test that says the quoting survived generation.
     */
    public function testASettingKeepsTheShapeOfItsValue(): void
    {
        $entityManager = $this->emptyDatabase();

        $entityManager->persist(new Setting(
            'site.name',
            ['title' => 'Trilobit', 'tagline' => 'Modular commerce, contacts and content.'],
            new DateTimeImmutable('2026-09-04T08:00:00+00:00'),
        ));
        $entityManager->flush();
        $entityManager->clear();

        $read = $entityManager->getRepository(Setting::class)->findOneBy(['key' => 'site.name']);

        self::assertInstanceOf(Setting::class, $read);
        self::assertSame(
            ['title' => 'Trilobit', 'tagline' => 'Modular commerce, contacts and content.'],
            $read->value(),
        );
    }

    public function testAMediaFileKeepsItsDimensionsAndItsSize(): void
    {
        $entityManager = $this->emptyDatabase();

        $entityManager->persist(new MediaFile(
            'media/2026/09/ammonite.jpg',
            'ammonite.jpg',
            'image/jpeg',
            184_320,
            new DateTimeImmutable('2026-09-04T08:00:00+00:00'),
            width: 1600,
            height: 900,
            alt: 'A spiral fossil on a grey background.',
        ));
        $entityManager->flush();
        $entityManager->clear();

        $read = $entityManager->getRepository(MediaFile::class)->findOneBy(['path' => 'media/2026/09/ammonite.jpg']);

        self::assertInstanceOf(MediaFile::class, $read);
        self::assertSame('ammonite.jpg', $read->originalName());
        self::assertSame('image/jpeg', $read->mime());
        self::assertSame(184_320, $read->sizeBytes());
        self::assertSame(1600, $read->width());
        self::assertSame(900, $read->height());
        self::assertSame('A spiral fossil on a grey background.', $read->alt());
    }

    /** A file that is not a picture has no dimensions, and that is not an error. */
    public function testAMediaFileMayHaveNoDimensions(): void
    {
        $entityManager = $this->emptyDatabase();

        $entityManager->persist(new MediaFile(
            'media/2026/09/terms.pdf',
            'terms.pdf',
            'application/pdf',
            42_000,
            new DateTimeImmutable('2026-09-04T08:00:00+00:00'),
        ));
        $entityManager->flush();
        $entityManager->clear();

        $read = $entityManager->getRepository(MediaFile::class)->findOneBy(['path' => 'media/2026/09/terms.pdf']);

        self::assertInstanceOf(MediaFile::class, $read);
        self::assertNull($read->width());
        self::assertNull($read->height());
        self::assertSame('', $read->alt());
    }

    private function emptyDatabase(): EntityManagerInterface
    {
        $this->schema = Database::schemaFor(self::class);
        $container = Boot::coreAlone();
        Migrations::run($container);

        return $container->getByType(EntityManagerInterface::class);
    }
}
