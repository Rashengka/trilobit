<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Shop;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Permissions;
use Trilobit\Core\Security\PermissionStructure;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;
use Trilobit\Shop\Security\ShopResource;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * The shop's resources, against a real database, in a build with the shop and
 * in one without it.
 *
 * Core does not name the shop, so the one way its resources get into a build
 * is the shop bringing them - and the claim worth a database is what a role
 * naming one of them does in each build. With the shop, the piece is a right
 * like any of Core's, asked the way the application asks. Without it, the row
 * still says it, the rest of the role still holds, and nobody is locked out:
 * Nette raises on a resource it was not given, so a piece carried as far as
 * the access list would stop the person using the application at all.
 */
#[CoversNothing]
final class ShopResourcesTest extends TestCase
{
    private const string SHOPKEEPER = 'shopkeeper';

    private string $schema = '';

    private ?Container $container = null;

    private string $password = '';

    protected function tearDown(): void
    {
        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->container = null;
        $this->password = '';

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testAPieceOfTheShopIsAllowedWhereTheShopIs(): void
    {
        $this->signedInHolding(Boot::container(), ['app.administration.shop.catalogue:edit']);

        self::assertTrue($this->permissions()->isAllowed(ShopResource::Catalogue, Privilege::Edit));
        self::assertTrue($this->permissions()->isAllowed(ShopResource::Shop, Privilege::View));
        self::assertTrue($this->permissions()->isAllowed(Resource::Administration, Privilege::View));
        self::assertFalse($this->permissions()->isAllowed(ShopResource::Catalogue, Privilege::Delete));
        self::assertFalse($this->permissions()->isAllowed(ShopResource::Price, Privilege::Edit));
    }

    /**
     * Asked the framework's way, which is the way a gate above a page asks:
     * the enum of a module goes through Nette\Security\User untouched, the way
     * Core's does.
     */
    public function testThePieceIsAnsweredThroughTheFrameworkToo(): void
    {
        $this->signedInHolding(Boot::container(), ['app.administration.shop.catalogue:edit']);

        $signedIn = $this->container()->getByType(SignedIn::class);
        self::assertTrue($signedIn->isAllowed(ShopResource::Catalogue, Privilege::Edit));
        self::assertFalse($signedIn->isAllowed(ShopResource::Price, Privilege::Edit));
    }

    /**
     * The price is not a bundle of its own - nobody may be given all of it and
     * whatever it grows - and still the whole of the shop reaches it, because
     * the price falls under the shop and running a shop includes its prices.
     */
    public function testTheWholeOfTheShopReachesThePrice(): void
    {
        $this->signedInHolding(Boot::container(), ['app.administration.shop:*']);

        self::assertTrue($this->permissions()->isAllowed(ShopResource::Price, Privilege::Edit));
        self::assertTrue($this->permissions()->isAllowed(ShopResource::Catalogue, Privilege::Purge));
        self::assertFalse($this->permissions()->isAllowed(Resource::Content, Privilege::View));
    }

    /**
     * The same role in a build without the shop: the piece reads back as
     * nothing and is left out, the row is not rewritten, and the rest of the
     * role still holds - so switching the shop back on is all it takes for the
     * piece to hold again.
     */
    public function testThePieceWaitsInABuildWithoutTheShop(): void
    {
        $this->signedInHolding(
            Boot::container(ModuleList::of(['cms' => true, 'crm' => true], Bootstrap::rootDirectory())),
            ['app.administration.shop.catalogue:edit', 'app.administration.content:edit'],
        );

        self::assertNull(
            $this->container()->getByType(PermissionStructure::class)->resourceNamed(ShopResource::Catalogue->value),
        );
        self::assertTrue($this->permissions()->isAllowed(Resource::Content, Privilege::Edit));
        self::assertTrue($this->permissions()->isAllowed(Resource::Administration, Privilege::View));

        $stored = $this->container()->getByType(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT permissions FROM core_role WHERE code = ?', [self::SHOPKEEPER]);
        self::assertIsString($stored);
        self::assertStringContainsString('app.administration.shop.catalogue:edit', $stored);
    }

    /**
     * And asking about it there is a loud refusal rather than a quiet no. No
     * page of this build can ask it - the shop's pages are not in it - so the
     * only code that could is code that should not, and a no would let it
     * look finished.
     */
    public function testAskingAboutThePieceInABuildWithoutTheShopIsRefused(): void
    {
        $this->signedInHolding(
            Boot::container(ModuleList::of(['cms' => true, 'crm' => true], Bootstrap::rootDirectory())),
            ['app.administration.shop.catalogue:edit'],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches("#'app\\.administration\\.shop\\.catalogue'#");

        $this->permissions()->isAllowed(ShopResource::Catalogue, Privilege::Edit);
    }

    /**
     * A business, an account holding a role of the business's own assembled
     * from $pieces, and that account signed in inside it.
     *
     * @param list<string> $pieces
     */
    private function signedInHolding(Container $container, array $pieces): void
    {
        $this->schema = Database::schemaFor(self::class);
        $this->container = $container;
        Migrations::run($container);

        $business = Tenants::enter($container, 'Ammonite Bikes');

        $this->password = Random::generate(24, 'a-zA-Z0-9');
        $account = new User(
            'shopkeeper@example.com',
            $container->getByType(Passwords::class)->hash($this->password),
            'Sam Shopkeeper',
            new DateTimeImmutable('2026-09-13T08:00:00+00:00'),
        );
        $container->getByType(Accounts::class)->save($account);

        $role = Role::ofBusiness($business, self::SHOPKEEPER, 'Shopkeeper', $pieces);
        $entityManager = $container->getByType(EntityManagerInterface::class);
        $entityManager->persist($role);
        $entityManager->persist(new Membership($business, $account, $role));
        $entityManager->flush();

        $container->getByType(SignedIn::class)->login('shopkeeper@example.com', $this->password);
    }

    private function permissions(): Permissions
    {
        return $this->container()->getByType(Permissions::class);
    }

    private function container(): Container
    {
        self::assertInstanceOf(Container::class, $this->container);

        return $this->container;
    }
}
