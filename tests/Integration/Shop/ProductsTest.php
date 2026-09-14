<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Shop;

use Nette\DI\Container;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Content\Categories;
use Trilobit\Core\Content\PathRefused;
use Trilobit\Core\Content\PathRegistry;
use Trilobit\Core\Contract\Content\ContentRef;
use Trilobit\Shop\Application\Product\Filing;
use Trilobit\Shop\Application\Product\ProductRefused;
use Trilobit\Shop\Application\Product\Products;
use Trilobit\Shop\Domain\Price\Money;
use Trilobit\Shop\Domain\Price\VatRate;
use Trilobit\Shop\Domain\Product\Product;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * Writing a product: the row of this module and the addresses in Core's
 * register, together (.ai/plans/30-obchod-katalog-t09.md).
 *
 * A product is in at least one category and answers in every one it is in
 * (decision R12); the main one, chosen by a person, is the permalink. What is
 * asserted here is that the two halves never part: an address refused in one
 * category leaves nothing written in the others, a product filed out of a
 * category leaves its address there leading to the permalink (Q10), and a
 * deleted product takes every address it had - redirects included - with it.
 */
#[CoversNothing]
final class ProductsTest extends TestCase
{
    private string $schema = '';

    private ?Container $container = null;

    /** @var array<string, string> the categories made for a test, by the last part of their address */
    private array $category = [];

    /**
     * The categories exist before a test body runs, because a filing is
     * written as an argument - evaluated before anything the test calls could
     * make them.
     */
    protected function setUp(): void
    {
        $this->setUpCategories();
    }

    protected function tearDown(): void
    {
        $this->container = null;
        $this->category = [];

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testANewProductAnswersInEveryCategoryItIsFiledInWithItsMainOneThePermalink(): void
    {
        $product = $this->ridge(new Filing($this->category['mountain'], [$this->category['sale']], 'ridge-29'));

        self::assertSame(['bikes/mountain/ridge-29', 'sale/ridge-29'], $this->pathsOf($product));
        self::assertSame('bikes/mountain/ridge-29', $this->products()->permalinkOf($product));
    }

    /** Decision R12: the permalink is the category somebody chose, not the one that happens to come first. */
    public function testTheMainCategoryIsTheOneChosenAsMain(): void
    {
        $product = $this->ridge(new Filing($this->category['sale'], [$this->category['mountain']], 'ridge-29'));

        self::assertSame('sale/ridge-29', $this->products()->permalinkOf($product));
    }

    /** Decision Q10: a link somebody was sent to the old category still reaches the product. */
    public function testFilingAProductOutOfACategoryLeavesItsAddressThereLeadingToThePermalink(): void
    {
        $product = $this->ridge(new Filing($this->category['mountain'], [$this->category['sale']], 'ridge-29'));

        $this->products()->fileAs($product, new Filing($this->category['mountain'], [], 'ridge-29'));

        self::assertSame(['bikes/mountain/ridge-29'], $this->pathsOf($product));
        self::assertSame('bikes/mountain/ridge-29', $this->registry()->find('sale/ridge-29')?->movedTo);
    }

    /** Choosing another main category moves the permalink and leaves every address answering. */
    public function testChangingTheMainCategoryMovesOnlyThePermalink(): void
    {
        $product = $this->ridge(new Filing($this->category['mountain'], [$this->category['sale']], 'ridge-29'));

        $this->products()->fileAs($product, new Filing($this->category['sale'], [$this->category['mountain']], 'ridge-29'));

        self::assertSame('sale/ridge-29', $this->products()->permalinkOf($product));
        self::assertSame(['sale/ridge-29', 'bikes/mountain/ridge-29'], $this->pathsOf($product));
        self::assertFalse($this->registry()->find('bikes/mountain/ridge-29')?->hasMoved() ?? true);
    }

    /** Decision R4: a new last part moves every address, and each old one keeps leading to its new one. */
    public function testChangingTheLastPartMovesEveryAddressAndLeavesRedirects(): void
    {
        $product = $this->ridge(new Filing($this->category['mountain'], [$this->category['sale']], 'ridge-29'));

        $this->products()->fileAs($product, new Filing($this->category['mountain'], [$this->category['sale']], 'ridge-29er'));

        self::assertSame(['bikes/mountain/ridge-29er', 'sale/ridge-29er'], $this->pathsOf($product));
        self::assertSame('bikes/mountain/ridge-29er', $this->registry()->find('bikes/mountain/ridge-29')?->movedTo);
        self::assertSame('sale/ridge-29er', $this->registry()->find('sale/ridge-29')?->movedTo);
    }

    /** The filing is read back the way a form offers it. */
    public function testTheFilingIsReadBackAsItWasGiven(): void
    {
        $filing = new Filing($this->category['sale'], [$this->category['mountain']], 'ridge-29');
        $product = $this->ridge($filing);

        $read = $this->products()->filingOf($product);

        self::assertSame($filing->main, $read->main);
        self::assertSame($filing->also, $read->also);
        self::assertSame($filing->segment, $read->segment);
    }

    /**
     * An address somebody else holds in one of the categories refuses the whole
     * product, and nothing of it is left: not the row, and not the address in
     * the category that was free.
     */
    public function testAnAddressTakenInOneCategoryRefusesTheNewProductWhole(): void
    {
        $this->setUpCategories();
        $this->registry()->register(new ContentRef('demo.page', '1'), 'sale/ridge-29', 'Somebody else', 'sale');

        try {
            $this->ridge(new Filing($this->category['mountain'], [$this->category['sale']], 'ridge-29'));
            self::fail('the product was saved over an address somebody else holds');
        } catch (PathRefused $refused) {
            self::assertStringContainsString('sale/ridge-29', $refused->getMessage());
        }

        self::assertSame([], $this->products()->all(), 'a product was left behind without its addresses');
        self::assertNull($this->registry()->find('bikes/mountain/ridge-29'), 'an address was left behind without its product');
    }

    /** And the same refusal when an existing product is filed into it, with the product left where it was. */
    public function testAnAddressTakenInOneCategoryRefusesTheNewFilingWhole(): void
    {
        $product = $this->ridge(new Filing($this->category['mountain'], [], 'ridge-29'));
        $this->registry()->register(new ContentRef('demo.page', '1'), 'sale/ridge-29', 'Somebody else', 'sale');

        try {
            $this->products()->fileAs($product, new Filing($this->category['sale'], [$this->category['mountain']], 'ridge-29'));
            self::fail('the product was filed over an address somebody else holds');
        } catch (PathRefused) {
            // What is asserted is what was left.
        }

        self::assertSame(['bikes/mountain/ridge-29'], $this->pathsOf($product));
        self::assertSame('bikes/mountain/ridge-29', $this->products()->permalinkOf($product));
    }

    public function testFilingUnderSomethingThatIsNotACategoryIsRefused(): void
    {
        $this->setUpCategories();

        $this->expectException(PathRefused::class);
        $this->expectExceptionMessage('There is no category');

        $this->ridge(new Filing('no-such-category', [], 'ridge-29'));
    }

    public function testAnSkuAnotherProductOfTheBusinessHasIsRefusedWithASentence(): void
    {
        $first = $this->ridge(new Filing($this->category['mountain'], [], 'ridge-29'));
        $this->products()->describe($first, 'Ridge 29', 'R-29', '', '');
        $second = $this->products()->create('Scree 27', new Filing($this->category['mountain'], [], 'scree-27'), $this->price(), new VatRate(2100));

        try {
            $this->products()->describe($second, 'Scree 27', 'R-29', '', '');
            self::fail('a second product was given an SKU the first one has');
        } catch (ProductRefused $refused) {
            self::assertStringContainsString('R-29', $refused->getMessage());
        }

        self::assertNull($second->sku());
        self::assertSame('R-29', $this->products()->find((int) $first->id())?->sku());
    }

    /** A product keeps its own SKU when it is saved again. */
    public function testAProductKeepsItsOwnSku(): void
    {
        $product = $this->ridge(new Filing($this->category['mountain'], [], 'ridge-29'));
        $this->products()->describe($product, 'Ridge 29', 'R-29', '', '');

        $this->products()->describe($product, 'Ridge 29, again', 'R-29', '', '');

        self::assertSame('R-29', $product->sku());
    }

    /** The register keeps a copy of the name for trails and menus, and it is told when the name changes. */
    public function testRenamingAProductRenamesItInTheRegister(): void
    {
        $product = $this->ridge(new Filing($this->category['mountain'], [$this->category['sale']], 'ridge-29'));

        $this->products()->describe($product, 'Ridge 29 Pro', null, '', '');

        self::assertSame('Ridge 29 Pro', $this->registry()->find('sale/ridge-29')?->label);
    }

    /** Deleting takes the product and every address it had, the one left leading to it included. */
    public function testDeletingAProductGivesEveryAddressBack(): void
    {
        $product = $this->ridge(new Filing($this->category['mountain'], [$this->category['sale']], 'ridge-29'));
        $this->products()->fileAs($product, new Filing($this->category['mountain'], [], 'ridge-29'));

        $this->products()->delete($product);

        self::assertSame([], $this->products()->all());
        self::assertNull($this->registry()->find('bikes/mountain/ridge-29'));
        self::assertNull($this->registry()->find('sale/ridge-29'), 'the redirect left by filing out outlived the product');
    }

    /** The suggestion is the register's, so it knows what is taken in the main category and leaves a product its own. */
    public function testALastPartIsSuggestedFromTheNameInTheMainCategory(): void
    {
        $product = $this->ridge(new Filing($this->category['mountain'], [], 'ridge-29'));

        self::assertSame('ridge-29-2', $this->products()->suggestSegment('Ridge 29', $this->category['mountain'], null));
        self::assertSame('ridge-29', $this->products()->suggestSegment('Ridge 29', $this->category['mountain'], $product));
        self::assertSame('ridge-29', $this->products()->suggestSegment('Ridge 29', $this->category['sale'], null));
    }

    public function testANewProductIsADraft(): void
    {
        $product = $this->ridge(new Filing($this->category['mountain'], [], 'ridge-29'));

        self::assertFalse($product->isPublished());
    }

    private function ridge(Filing $filing): Product
    {
        return $this->products()->create('Ridge 29', $filing, $this->price(), new VatRate(2100));
    }

    private function price(): Money
    {
        return new Money(2499000, 'CZK');
    }

    /** @return list<string> every live address of $product, the permalink first */
    private function pathsOf(Product $product): array
    {
        return array_map(static fn(Address $address): string => $address->path, $this->products()->addressesOf($product));
    }

    private function products(): Products
    {
        $this->setUpCategories();

        return $this->container()->getByType(Products::class);
    }

    private function registry(): PathRegistry
    {
        return $this->container()->getByType(PathRegistry::class);
    }

    /** Bikes with Mountain under it, and Sale beside it - made once per test, the first time anything asks. */
    private function setUpCategories(): void
    {
        if ($this->category !== []) {
            return;
        }

        $categories = $this->container()->getByType(Categories::class);
        $bikes = $categories->create('Bikes', 'bikes', null);
        $this->category = [
            'bikes' => $bikes->ref->id,
            'mountain' => $categories->create('Mountain bikes', 'mountain', $bikes->ref->id)->ref->id,
            'sale' => $categories->create('Sale', 'sale', null)->ref->id,
        ];
    }

    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::container();
        Migrations::run($container);
        Tenants::enter($container, 'Ammonite Bikes');

        return $this->container = $container;
    }
}
