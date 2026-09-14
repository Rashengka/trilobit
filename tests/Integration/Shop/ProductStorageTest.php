<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Shop;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Tenancy\Tenancy;
use Trilobit\Shop\Domain\Price\Money;
use Trilobit\Shop\Domain\Price\VatRate;
use Trilobit\Shop\Domain\Product\Product;
use Trilobit\Shop\Domain\Product\ProductRepository;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * A product as the database keeps it: its price with the currency it was set
 * in, its rate of tax, and an SKU that is the business's own.
 *
 * The SKU is where the table itself has to say no. It is unique within one
 * business and not across two - each keeps its own numbering - and it may be
 * left out, by as many products as want to: the index is over the business and
 * the SKU, and MariaDB holds two empty SKUs to be different, which is exactly
 * what "optional" has to mean here.
 */
#[CoversNothing]
final class ProductStorageTest extends TestCase
{
    private string $schema = '';

    private ?Container $container = null;

    protected function tearDown(): void
    {
        $this->container = null;

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testAProductKeepsItsPriceItsCurrencyAndItsRate(): void
    {
        $business = $this->business();
        $product = $this->product($business, 'Ridge 29', 'R-29');
        $this->products()->save($product);
        $id = $product->id();
        self::assertNotNull($id);

        $this->container()->getByType(EntityManagerInterface::class)->clear();
        $read = $this->products()->find($id);

        self::assertNotNull($read);
        self::assertSame(2499000, $read->price()->amount());
        self::assertSame('CZK', $read->price()->currency());
        self::assertSame(2100, $read->vatRate()->basisPoints());
        self::assertSame(3023790, $read->priceWithVat()->amount());
        self::assertSame('R-29', $read->sku());
    }

    public function testTwoProductsOfOneBusinessCannotShareAnSku(): void
    {
        $business = $this->business();
        $this->products()->save($this->product($business, 'Ridge 29', 'R-29'));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->products()->save($this->product($business, 'Ridge 27.5', 'R-29'));
    }

    public function testTwoBusinessesMayEachHaveTheSameSku(): void
    {
        $this->products()->save($this->product($this->business(), 'Ridge 29', 'R-29'));

        Tenants::switchTo($this->container(), Tenants::create($this->container(), 'Belemnite Books'));
        // The business as the application holds it once the process is
        // inside it - what every product is made with outside a test.
        $other = $this->container()->getByType(Tenancy::class)->tenant();
        $this->products()->save($this->product($other, 'Ridge 29', 'R-29'));

        self::assertNotNull($this->products()->findBySku('R-29'));
    }

    public function testAnyNumberOfProductsMayHaveNoSku(): void
    {
        $business = $this->business();
        $this->products()->save($this->product($business, 'Ridge 29', null));
        $this->products()->save($this->product($business, 'Ridge 27.5', null));

        self::assertCount(2, $this->products()->all());
    }

    private function product(Tenant $business, string $name, ?string $sku): Product
    {
        $product = new Product(
            $business,
            $name,
            new Money(2499000, 'CZK'),
            new VatRate(2100),
            new DateTimeImmutable('2026-09-14T08:00:00+00:00'),
        );
        $product->describe($name, $sku, '', '', new DateTimeImmutable('2026-09-14T08:00:00+00:00'));

        return $product;
    }

    private function business(): Tenant
    {
        return Tenants::enter($this->container(), 'Ammonite Bikes');
    }

    private function products(): ProductRepository
    {
        return $this->container()->getByType(ProductRepository::class);
    }

    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::container();
        Migrations::run($container);

        return $this->container = $container;
    }
}
