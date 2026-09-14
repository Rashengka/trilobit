<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Shop;

use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Content\Categories;
use Trilobit\Core\Domain\Media\MediaFile;
use Trilobit\Core\Media\MediaStorage;
use Trilobit\Core\Media\UploadRefused;
use Trilobit\Core\Media\Variant;
use Trilobit\Shop\Application\Product\Filing;
use Trilobit\Shop\Application\Product\Products;
use Trilobit\Shop\Domain\Price\Money;
use Trilobit\Shop\Domain\Price\VatRate;
use Trilobit\Shop\Domain\Product\Product;
use Trilobit\Shop\Domain\Product\ProductImage;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\MediaDirectories;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Pictures;
use Trilobit\Tests\Tenants;

/**
 * A product's pictures (.ai/plans/30-obchod-katalog-t09.md, Q4): a picture is
 * Core's - a Trilobit\Core\Domain\Media\MediaFile taken in through the media
 * library - and what the product holds is the binding to it.
 *
 * So removing a picture from a product takes the binding and leaves the file,
 * and so does deleting the product: deleting files is for plan 16, when there
 * is a bin to take them back out of. What is asserted besides is that nothing
 * is bound that the library refused, and that a binding of one product cannot
 * be reached through another.
 */
#[CoversNothing]
final class ProductPicturesTest extends TestCase
{
    private string $schema = '';

    private string $directory = '';

    private ?Container $container = null;

    private ?string $mountain = null;

    protected function tearDown(): void
    {
        $this->container = null;
        $this->mountain = null;
        MediaDirectories::delete($this->directory);
        $this->directory = '';

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testAPictureIsTakenInThroughTheLibraryAndBoundToTheProduct(): void
    {
        $product = $this->ridge();

        $this->products()->addPicture($product, $this->file(Pictures::jpeg(40, 30)), 'ridge.jpg', 'The Ridge 29 from the side');

        $pictures = $this->products()->picturesOf($product);
        self::assertCount(1, $pictures);
        $file = $pictures[0]->file();
        self::assertSame('image/jpeg', $file->mime());
        self::assertSame(40, $file->width());
        self::assertSame('The Ridge 29 from the side', $file->alt());
        self::assertFileExists($this->directory . '/public/' . MediaStorage::variantPath($file->path(), Variant::Thumb));
    }

    public function testPicturesKeepTheOrderTheyWereAddedIn(): void
    {
        $product = $this->ridge();

        $this->products()->addPicture($product, $this->file(Pictures::jpeg(40, 30)), 'side.jpg', '');
        $this->products()->addPicture($product, $this->file(Pictures::png(30, 40)), 'front.png', '');

        self::assertSame(
            ['side.jpg', 'front.png'],
            array_map(static fn(ProductImage $picture): string => $picture->file()->originalName(), $this->products()->picturesOf($product)),
        );
    }

    /** Decision Q4: the binding goes, the file stays until plan 16 gives it a bin. */
    public function testRemovingAPictureTakesTheBindingAndLeavesTheFile(): void
    {
        $product = $this->ridge();
        $picture = $this->products()->addPicture($product, $this->file(Pictures::jpeg(40, 30)), 'ridge.jpg', '');
        $path = $picture->file()->path();

        $this->products()->removePicture($product, (int) $picture->id());

        self::assertSame([], $this->products()->picturesOf($product));
        self::assertCount(1, $this->mediaFiles(), 'the picture itself was deleted');
        self::assertFileExists($this->directory . '/originals/' . $path);
    }

    public function testAFileTheLibraryRefusesLeavesNothingBound(): void
    {
        $product = $this->ridge();

        try {
            $this->products()->addPicture($product, $this->file('<svg xmlns="http://www.w3.org/2000/svg"/>'), 'logo.svg', '');
            self::fail('a drawing was taken in as a picture');
        } catch (UploadRefused) {
            // What is asserted is what was left.
        }

        self::assertSame([], $this->products()->picturesOf($product));
        self::assertSame([], $this->mediaFiles());
    }

    public function testDeletingAProductTakesItsBindingsAndLeavesItsPictures(): void
    {
        $product = $this->ridge();
        $this->products()->addPicture($product, $this->file(Pictures::jpeg(40, 30)), 'ridge.jpg', '');

        $this->products()->delete($product);

        self::assertSame([], $this->products()->all());
        self::assertSame([], $this->container()->getByType(EntityManagerInterface::class)->getRepository(ProductImage::class)->findAll());
        self::assertCount(1, $this->mediaFiles());
    }

    /** A binding is reached through its product and no other, whatever number a form sends. */
    public function testAPictureOfAnotherProductIsNotReachedThroughThisOne(): void
    {
        $ridge = $this->ridge();
        $scree = $this->products()->create(
            'Scree 27',
            new Filing($this->mountain(), [], 'scree-27'),
            new Money(1999000, 'CZK'),
            new VatRate(2100),
        );
        $picture = $this->products()->addPicture($ridge, $this->file(Pictures::jpeg(40, 30)), 'ridge.jpg', '');

        self::assertNull($this->products()->pictureOf($scree, (int) $picture->id()));

        $this->products()->removePicture($scree, (int) $picture->id());
        self::assertCount(1, $this->products()->picturesOf($ridge), 'another product\'s picture was removed');
    }

    private function ridge(): Product
    {
        return $this->products()->create(
            'Ridge 29',
            new Filing($this->mountain(), [], 'ridge-29'),
            new Money(2499000, 'CZK'),
            new VatRate(2100),
        );
    }

    /** A file holding $bytes, the way an upload arrives: somewhere temporary, under a name of its own. */
    private function file(string $bytes): string
    {
        $file = $this->directory . '/uploads/' . bin2hex(random_bytes(6));
        FileSystem::write($file, $bytes);

        return $file;
    }

    /** @return list<MediaFile> */
    private function mediaFiles(): array
    {
        return $this->container()->getByType(EntityManagerInterface::class)->getRepository(MediaFile::class)->findAll();
    }

    /** The one category of the test, made the first time it is asked for. */
    private function mountain(): string
    {
        return $this->mountain ??= $this->container()->getByType(Categories::class)
            ->create('Mountain bikes', 'mountain', null)->ref->id;
    }

    private function products(): Products
    {
        return $this->container()->getByType(Products::class);
    }

    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::container();
        $this->directory = MediaDirectories::temporaryFor($container);
        Migrations::run($container);
        Tenants::enter($container, 'Ammonite Bikes');

        return $this->container = $container;
    }
}
