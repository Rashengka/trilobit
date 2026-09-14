<?php

declare(strict_types=1);

namespace Trilobit\Shop\Application\Product;

use DateTimeImmutable;
use LogicException;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Content\Categories;
use Trilobit\Core\Content\PathRefused;
use Trilobit\Core\Content\PathRegistry;
use Trilobit\Core\Contract\Content\ContentRef;
use Trilobit\Core\Media\MediaLibrary;
use Trilobit\Core\Media\MediaNotStored;
use Trilobit\Core\Media\UploadRefused;
use Trilobit\Core\Tenancy\Tenancy;
use Trilobit\Shop\Domain\Price\Money;
use Trilobit\Shop\Domain\Price\VatRate;
use Trilobit\Shop\Domain\Product\Product;
use Trilobit\Shop\Domain\Product\ProductImage;
use Trilobit\Shop\Domain\Product\ProductImageRepository;
use Trilobit\Shop\Domain\Product\ProductRepository;

/**
 * Everything writing a product involves, in the one place that knows a product
 * is a row of this module and as many rows of Core's register as it has
 * categories.
 *
 * **A product answers in every category it is filed in, and one of them is its
 * permalink** (decision R12 of .ai/plans/01e-routing-a-provazani-obsahu.md):
 * two categories, two addresses, both answering. The main category is chosen
 * by a person and is the permalink; filing the product somewhere else never
 * moves it (see Filing).
 *
 * **Every address is asked about before any is written.** A product filed into
 * three categories whose last part is taken in the third is refused whole,
 * rather than saved into two of the three: the register is asked what it would
 * refuse (PathRegistry::refusalFor()) for every address first, and only then
 * written to. A new product is removed again if the register refuses it anyway
 * - somebody else claiming the address in between - because a product no
 * address leads to is not a draft, it is litter.
 *
 * **Filing a product out of a category leaves its address there leading to the
 * permalink** (decision Q10 of .ai/plans/30-obchod-katalog-t09.md), so a link
 * somebody was sent reaches the product rather than nothing. A new last part
 * moves every address, and each old one keeps leading to its new one
 * (decision R4). Categories are Core's (Trilobit\Core\Content\Categories), so
 * nothing here names the module that arranges them.
 *
 * **What it costs is written by a method of its own**, reprice(), and nothing
 * else writes it. Who may call it is decided where the request is - the right
 * is `app.administration.shop.price`, and it is the presenter that knows who
 * is asking.
 */
final readonly class Products
{
    public function __construct(
        private ProductRepository $products,
        private PathRegistry $addresses,
        private Categories $categories,
        private Tenancy $tenancy,
        private PriceSettings $prices,
        private ProductImageRepository $pictures,
        private MediaLibrary $library,
    ) {}

    public function find(int $id): ?Product
    {
        return $this->products->find($id);
    }

    /** @return list<Product> by name */
    public function all(): array
    {
        return $this->products->all();
    }

    /** The installation's currency and the rate a new product starts with. */
    public function prices(): PriceSettings
    {
        return $this->prices;
    }

    /**
     * A new product called $name, filed as $filing, in draft.
     *
     * Its addresses are claimed straight away, before anybody may see it, so
     * that writing a product and holding on to where it will live are one act
     * - and the main one first, because the first address anything gets is its
     * permalink.
     */
    public function create(string $name, Filing $filing, Money $price, VatRate $rate): Product
    {
        $targets = $this->targetsOf($filing, null);

        $product = new Product($this->tenancy->tenant(), $name, $price, $rate, $this->now());
        $this->products->save($product);
        $ref = $this->refOf($product);

        try {
            foreach ($targets as [$path, $parent]) {
                $this->addresses->register($ref, $path, $name, $parent);
            }
        } catch (PathRefused $refused) {
            foreach (array_reverse($this->addresses->addressesOf($ref)) as $address) {
                $this->addresses->forget($address->path);
            }

            $this->products->remove($product);

            throw $refused;
        }

        return $product;
    }

    /**
     * Everything about the product but where it is filed and what it costs.
     * The register is told the new name, because it keeps a copy of it for
     * trails and menus.
     *
     * @throws ProductRefused when another product of the business has $sku
     */
    public function describe(Product $product, string $name, ?string $sku, string $perex, string $description): void
    {
        $refusal = $this->skuRefusal($sku, $product);
        if ($refusal instanceof ProductRefused) {
            throw $refusal;
        }

        $product->describe($name, $sku, $perex, $description, $this->now());
        $this->products->save($product);
        $this->addresses->describe($this->refOf($product), $name);
    }

    /**
     * Why $sku cannot be $for's - another product of the business has it - or
     * null when it can; asked without anything being written, so that a new
     * product with a taken SKU is refused before it exists rather than after.
     * No SKU at all is never taken.
     */
    public function skuRefusal(?string $sku, ?Product $for): ?ProductRefused
    {
        $sku = $sku === null ? '' : trim($sku);
        if ($sku === '') {
            return null;
        }

        $holder = $this->products->findBySku($sku);

        return $holder instanceof Product && $holder !== $for ? ProductRefused::skuTaken($sku) : null;
    }

    /**
     * A last part for a product called $name in the category $main that the
     * register would accept there, or '' when the name holds nothing to make
     * one of. The product being edited keeps its own.
     *
     * It is asked of the main category alone, because a suggestion is one
     * word and the categories may each hold a different set of them: an
     * address taken in one of the others is refused when the product is
     * saved, with a sentence saying where.
     */
    public function suggestSegment(string $name, string $main, ?Product $for): string
    {
        return $this->addresses->suggest(
            $name,
            $this->categories->pathOf($main) ?? throw PathRefused::noSuchCategory($main),
            $for?->ref(),
        );
    }

    /** What it costs before tax, and the rate of tax on it; see the class for who may call this. */
    public function reprice(Product $product, Money $price, VatRate $rate): void
    {
        $product->reprice($price, $rate, $this->now());
        $this->products->save($product);
    }

    /**
     * Files the product as $filing says: into the categories it is not in yet,
     * out of the ones it is no longer in - leaving a redirect to the permalink
     * behind - and to a new last part everywhere it stays, leaving a redirect to
     * each new address. The main category becomes the permalink.
     */
    public function fileAs(Product $product, Filing $filing): void
    {
        $ref = $this->refOf($product);
        $targets = $this->targetsOf($filing, $ref);
        $standing = $this->standingOf($ref);

        foreach ($targets as $category => [$path, $parent]) {
            $was = $standing[$category] ?? null;
            if ($was === null) {
                $this->addresses->register($ref, $path, $product->name(), $parent);
            } elseif ($was !== $path) {
                $this->addresses->rename($was, $path);
            }
        }

        $this->addresses->makeCanonical($ref, $targets[$filing->main][0]);

        foreach ($standing as $category => $path) {
            if (!isset($targets[$category])) {
                $this->addresses->retire($path);
            }
        }
    }

    /**
     * Where the product is filed, the way a form offers it: the main category
     * is the one its permalink is in, the others in the order of their
     * addresses. An address that is in no category - which nothing here
     * writes - is left out of it, and a main category of '' says there is
     * none to offer.
     */
    public function filingOf(Product $product): Filing
    {
        $main = '';
        $segment = '';
        $also = [];
        foreach ($this->addressesOf($product) as $address) {
            $category = $this->categories->placementOf($address->path);
            if ($category?->category === null) {
                continue;
            }

            if ($address->isCanonical()) {
                $main = $category->category;
                $segment = $category->segment;
            } else {
                $also[] = $category->category;
            }
        }

        return new Filing($main, $also, $segment);
    }

    public function publish(Product $product): void
    {
        $product->publish($this->now());
        $this->products->save($product);
    }

    public function withdraw(Product $product): void
    {
        $product->withdraw($this->now());
        $this->products->save($product);
    }

    /**
     * Takes the product and every address it answers at.
     *
     * The permalink goes last, because the register holds on to it while any
     * other address of the same content is registered. The redirects the
     * product left behind go with the addresses they lead to - the register
     * keeps no redirect to something that is gone.
     */
    public function delete(Product $product): void
    {
        // Its pictures are taken off first; the files stay in the library
        // (see ProductImage).
        foreach ($this->pictures->of($product) as $picture) {
            $this->pictures->remove($picture);
        }

        foreach (array_reverse($this->addressesOf($product)) as $address) {
            $this->addresses->forget($address->path);
        }

        $this->products->remove($product);
    }

    /** @return list<ProductImage> the pictures of $product, the first one first */
    public function picturesOf(Product $product): array
    {
        return $this->pictures->of($product);
    }

    /**
     * The picture $id of $product, or null when it is not one of its - a
     * number sent by a form is reached through the product it was sent for
     * and no other.
     */
    public function pictureOf(Product $product, int $id): ?ProductImage
    {
        $picture = $this->pictures->find($id);

        return $picture instanceof ProductImage && $picture->product()->id() === $product->id() ? $picture : null;
    }

    /**
     * Takes the picture at $file into the media library and puts it after the
     * product's other pictures.
     *
     * The library writes the files and the row or neither; the binding is
     * written after it, and a binding that could not be written leaves a
     * picture in the library that nothing shows - which is what taking a
     * picture off a product leaves as well, until plan 16 gives the library a
     * bin.
     *
     * @throws UploadRefused when the file is not a picture the library takes
     * @throws MediaNotStored when it is, and it could not be kept
     */
    public function addPicture(Product $product, string $file, string $originalName, string $alt): ProductImage
    {
        $media = $this->library->upload($file, $originalName, $alt);
        $picture = new ProductImage($this->tenancy->tenant(), $product, $media, $this->pictures->nextPosition($product));
        $this->pictures->save($picture);

        return $picture;
    }

    /** Takes the picture $id off $product, and only the binding: the file stays in the library. */
    public function removePicture(Product $product, int $id): void
    {
        $picture = $this->pictureOf($product, $id);
        if ($picture instanceof ProductImage) {
            $this->pictures->remove($picture);
        }
    }

    /** @return list<Address> every address the product answers at, the permalink first */
    public function addressesOf(Product $product): array
    {
        return $this->addresses->addressesOf($this->refOf($product));
    }

    /** Where the product's permalink is, or null while it has no address at all. */
    public function permalinkOf(Product $product): ?string
    {
        return $this->addresses->canonicalPathOf($this->refOf($product));
    }

    /**
     * The address in each category of $filing, by the category, the main one
     * first - every one of them asked of the register before any is written.
     *
     * @return non-empty-array<string, array{string, string}> the address and the category's address, by the category
     *
     * @throws PathRefused when a category is not one, the last part is not one part, or an address is taken
     */
    private function targetsOf(Filing $filing, ?ContentRef $for): array
    {
        $targets = [];
        foreach ($filing->categories() as $category) {
            $parent = $this->categories->pathOf($category) ?? throw PathRefused::noSuchCategory($category);
            $path = $this->addresses->addressUnder($parent, $filing->segment);

            $refusal = $this->addresses->refusalFor($path, $for);
            if ($refusal instanceof PathRefused) {
                throw $refusal;
            }

            $targets[$category] = [$path, $parent];
        }

        return $targets;
    }

    /**
     * Every live address of $ref, by the category it is in - or, for one in no
     * category, by the address itself, which no category is called and which
     * fileAs() therefore gives up.
     *
     * @return array<string, string>
     */
    private function standingOf(ContentRef $ref): array
    {
        $standing = [];
        foreach ($this->addresses->addressesOf($ref) as $address) {
            $category = $this->categories->placementOf($address->path)?->category;
            $standing[$category ?? '/' . $address->path] = $address->path;
        }

        return $standing;
    }

    /**
     * A product that has never been saved has no identifier, so nothing can
     * point at it. Everything here saves first, so meeting one is a mistake in
     * this class rather than something a product can be in.
     */
    private function refOf(Product $product): ContentRef
    {
        return $product->ref() ?? throw new LogicException('A product has to be saved before it can have an address.');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
