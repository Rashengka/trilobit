<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Shop;

use Contributte\Console\Application;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use Nette\Security\User as SignedIn;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Config\Mode;
use Trilobit\Core\Console\SeedCommand;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Presentation\Listing\Listing;
use Trilobit\Core\Security\Permissions;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;
use Trilobit\Shop\Application\Product\Products;
use Trilobit\Shop\Domain\Product\Product;
use Trilobit\Shop\Security\ShopResource;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * What the shop adds to `bin/trilobit app:seed`: a catalogue for each business,
 * and somebody who keeps it (.ai/plans/30-obchod-katalog-t09.md, V3).
 *
 * The catalogue is what clicking through the administration has to meet: more
 * products than a page of the list holds, a product in two categories whose
 * permalink is the first and one whose permalink is the second, a draft, and
 * the rates of tax a shop has besides the standard one. The person is the role
 * in between - may write the catalogue, may not change a price - so that the
 * one thing about it worth clicking through can be.
 *
 * The build has the shop and no other module: the categories are Core's, and
 * a shop without the module that arranges pages is a build the seed has to
 * fill too.
 */
#[CoversNothing]
final class SeededCatalogueTest extends TestCase
{
    private const string CATALOGUE_KEEPER = 'ammonite-cataloguer@example.com';

    private string $schema = '';

    private ?Container $container = null;

    /** What the mode variable held before this test set it - false when it was not set - or null while untouched. */
    private string|false|null $modeBefore = null;

    protected function tearDown(): void
    {
        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->container = null;

        if ($this->modeBefore !== null) {
            putenv($this->modeBefore === false ? Mode::VARIABLE : Mode::VARIABLE . '=' . $this->modeBefore);
            $this->modeBefore = null;
        }

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    /** More than a page of the list, so that it is paged and a filter taking somebody back to its first page can be tried. */
    public function testEachBusinessHasMoreProductsThanAPageOfTheListHolds(): void
    {
        $container = $this->seeded();

        $this->enter($container, 'Ammonite Bikes');
        self::assertGreaterThan(Listing::PER_PAGE, count($this->products($container)->all()));
    }

    /** Each business has its own, named after it, at the same addresses. */
    public function testEachBusinessHasItsOwnCatalogueAtTheSameAddresses(): void
    {
        $container = $this->seeded();

        // Each read inside its own business: the register is scoped by it, so
        // from inside the other one a product has no address at all.
        $this->enter($container, 'Ammonite Bikes');
        $ammonite = $this->pathsOf($container, $this->named($container, 'Ammonite Ridge 29'));

        $this->enter($container, 'Belemnite Books');
        $belemnite = $this->pathsOf($container, $this->named($container, 'Belemnite Ridge 29'));

        self::assertSame(['bikes/mountain/ridge-29', 'sale/ridge-29'], $ammonite);
        self::assertSame($ammonite, $belemnite);
        foreach ($this->products($container)->all() as $product) {
            self::assertStringStartsWith('Belemnite ', $product->name(), 'a product of the other business turned up');
        }
    }

    /** Decision R12, to click through: two addresses, and the permalink in the category chosen as main - first or not. */
    public function testAProductIsInTwoCategoriesWithThePermalinkWhereItWasChosen(): void
    {
        $container = $this->seeded();
        $this->enter($container, 'Ammonite Bikes');

        $ridge = $this->named($container, 'Ammonite Ridge 29');
        self::assertSame(['bikes/mountain/ridge-29', 'sale/ridge-29'], $this->pathsOf($container, $ridge));

        $talus = $this->named($container, 'Ammonite Talus Enduro');
        self::assertSame(['sale/talus-enduro', 'bikes/mountain/talus-enduro'], $this->pathsOf($container, $talus));
    }

    public function testThereIsADraftAndThereArePricesAtEveryKindOfRate(): void
    {
        $container = $this->seeded();
        $this->enter($container, 'Ammonite Bikes');

        self::assertFalse($this->named($container, 'Ammonite Cirque Junior')->isPublished());
        self::assertTrue($this->named($container, 'Ammonite Ridge 29')->isPublished());

        self::assertSame(2100, $this->named($container, 'Ammonite Ridge 29')->vatRate()->basisPoints());
        self::assertSame(1200, $this->named($container, 'Ammonite Trail map of the hills')->vatRate()->basisPoints());
        self::assertSame(0, $this->named($container, 'Ammonite Gift card')->vatRate()->basisPoints());
        self::assertNull($this->named($container, 'Ammonite Gift card')->sku(), 'no product shows an SKU is optional');

        foreach ($this->products($container)->all() as $product) {
            self::assertGreaterThan(0, $product->price()->amount(), $product->name() . ' has no price');
            self::assertSame('CZK', $product->price()->currency());
        }
    }

    /**
     * The role in between, seeded by the module whose resources it names:
     * signs in with the password printed beside it, may write the catalogue,
     * may not change what anything costs, and holds nothing in the other
     * business.
     */
    public function testTheCatalogueKeeperMayWriteTheCatalogueAndNotChangeAPrice(): void
    {
        $container = $this->seeded($output);

        preg_match_all(SeedCommand::ACCOUNT_LINE, $output, $lines, PREG_SET_ORDER);
        $passwords = [];
        foreach ($lines as $line) {
            $passwords[$line[1]] = $line[2];
        }

        self::assertArrayHasKey(self::CATALOGUE_KEEPER, $passwords, "the catalogue keeper was not printed:\n" . $output);
        self::assertStringContainsString('may not change what anything in it costs', $output);

        $container->getByType(SignedIn::class)->login(self::CATALOGUE_KEEPER, $passwords[self::CATALOGUE_KEEPER]);
        $this->enter($container, 'Ammonite Bikes');
        $permissions = $container->getByType(Permissions::class);

        self::assertTrue($permissions->isAllowed(ShopResource::Catalogue, Privilege::Edit));
        self::assertTrue($permissions->isAllowed(ShopResource::Catalogue, Privilege::Delete));
        self::assertFalse($permissions->isAllowed(ShopResource::Price, Privilege::Edit));
        self::assertFalse($permissions->isAllowed(Resource::Account, Privilege::View));

        $this->enter($container, 'Belemnite Books');
        self::assertFalse($container->getByType(Permissions::class)->isAllowed(ShopResource::Catalogue, Privilege::View));
    }

    private function named(Container $container, string $name): Product
    {
        foreach ($this->products($container)->all() as $product) {
            if ($product->name() === $name) {
                return $product;
            }
        }

        self::fail('the seed made no product called ' . $name);
    }

    /** @return list<string> */
    private function pathsOf(Container $container, Product $product): array
    {
        return array_map(static fn(Address $address): string => $address->path, $this->products($container)->addressesOf($product));
    }

    private function products(Container $container): Products
    {
        return $container->getByType(Products::class);
    }

    /** @param-out string $output */
    private function seeded(?string &$output = null): Container
    {
        $this->schema = Database::schemaFor(self::class);

        // Stated rather than taken from the machine; see
        // Trilobit\Tests\Integration\Cms\SeededContentTest::seeded().
        $this->modeBefore ??= getenv(Mode::VARIABLE);
        putenv(Mode::VARIABLE . '=' . Mode::Dev->value);

        $container = Boot::container(
            ModuleList::of(['cms' => false, 'crm' => false, 'shop' => true], Bootstrap::rootDirectory()),
        );
        Migrations::run($container);

        $tester = new CommandTester($container->getByType(Application::class)->find('app:seed'));
        $status = $tester->execute([], ['interactive' => false, 'capture_stderr_separately' => true]);
        $output = $tester->getDisplay() . $tester->getErrorOutput();
        self::assertSame(Command::SUCCESS, $status, $output);

        return $this->container = $container;
    }

    private function enter(Container $container, string $name): void
    {
        $tenant = $container->getByType(EntityManagerInterface::class)
            ->getRepository(Tenant::class)
            ->findOneBy(['name' => $name]);
        self::assertInstanceOf(Tenant::class, $tenant, 'there is no business called ' . $name);

        Tenants::switchTo($container, $tenant);
    }
}
