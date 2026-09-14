<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Shop;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Dom\HTMLDocument;
use Nette\Application\BadRequestException;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\ForwardResponse;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Content\Categories;
use Trilobit\Core\Content\PathRegistry;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Grant;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Tenancy\Tenancy;
use Trilobit\Shop\Application\Product\Filing;
use Trilobit\Shop\Application\Product\Products;
use Trilobit\Shop\Domain\Price\Money;
use Trilobit\Shop\Domain\Price\VatRate;
use Trilobit\Shop\Domain\Product\Product;
use Trilobit\Shop\Security\ShopResource;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * The catalogue in the administration, the way a person writes it: through the
 * form, with the form's own answers coming back.
 *
 * **The price is the claim this suite is mostly about.** What a product costs
 * and the rate of tax on it are a right of their own,
 * `app.administration.shop.price`, a sibling of the catalogue so that it can
 * be withheld (.ai/plans/30-obchod-katalog-t09.md, Q1). The role in between -
 * may write the catalogue, may not change a price - is asked every way a price
 * could reach a product: the fields it is shown, a form sent with a price in it
 * anyway, a new product, and the same form sent to another action of the
 * presenter. None of them may change what anything costs.
 *
 * A form arrives through Nette\Application\UI\Presenter::processSignal(), which
 * asks nothing of any method, so what writing needs is asked in the handler as
 * well as above the actions - which is why a form sent to the list, by
 * somebody who may only look, is one of the cases.
 *
 * The build has the shop and neither of the other modules: the categories are
 * Core's, and the catalogue has to be writable without the module that happens
 * to arrange them today.
 */
#[CoversNothing]
final class ProductAdministrationTest extends TestCase
{
    private const string PRESENTER = 'Shop:Admin:Product';

    private const string SUBMIT = 'product-submit';

    private string $schema = '';

    private ?Container $container = null;

    private ?string $fetchSite = null;

    /** @var array<string, string> the categories of the business, by the last part of their address */
    private array $category = [];

    protected function setUp(): void
    {
        $this->fetchSite = isset($_SERVER['HTTP_SEC_FETCH_SITE']) && is_string($_SERVER['HTTP_SEC_FETCH_SITE'])
            ? $_SERVER['HTTP_SEC_FETCH_SITE']
            : null;
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
    }

    protected function tearDown(): void
    {
        if ($this->fetchSite === null) {
            unset($_SERVER['HTTP_SEC_FETCH_SITE']);
        } else {
            $_SERVER['HTTP_SEC_FETCH_SITE'] = $this->fetchSite;
        }

        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->container = null;
        $this->category = [];

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testWritingAProductSavesItsPriceItsRateAndItsCategories(): void
    {
        $this->signedInHolding(['app:*']);

        $response = $this->submit('add', $this->values(['also' => [$this->category['sale']]]));

        self::assertInstanceOf(RedirectResponse::class, $response, $this->saidIn($response));
        $product = $this->onlyProduct();
        self::assertSame(2499000, $product->price()->amount());
        self::assertSame('CZK', $product->price()->currency());
        self::assertSame(2100, $product->vatRate()->basisPoints());
        self::assertSame(['bikes/mountain/ridge-29', 'sale/ridge-29'], $this->pathsOf($product));
        self::assertFalse($product->isPublished(), 'a product starts as a draft');
    }

    public function testALastPartLeftEmptyIsMadeFromTheName(): void
    {
        $this->signedInHolding(['app:*']);

        $this->submit('add', $this->values(['segment' => '']));

        self::assertSame('bikes/mountain/ridge-29', $this->products()->permalinkOf($this->onlyProduct()));
    }

    /** A price nobody can read is refused beside the field it was written in, and nothing is saved. */
    public function testAPriceThatCannotBeReadIsRefusedBesideItsField(): void
    {
        $this->signedInHolding(['app:*']);

        $document = $this->pageOf($this->submit('add', $this->values(['price' => 'twelve'])));

        self::assertSame('true', $document->querySelector('#frm-product-price')?->getAttribute('aria-invalid'));
        self::assertStringContainsString("'twelve' is not a price", $this->textOf($document));
        self::assertSame([], $this->products()->all());
    }

    public function testAnSkuAnotherProductHasIsRefusedOnTheFormAndNothingIsWritten(): void
    {
        $this->signedInHolding(['app:*']);
        $this->submit('add', $this->values(['sku' => 'R-29']));

        $response = $this->submit('add', $this->values(['name' => 'Scree 27', 'segment' => 'scree-27', 'sku' => 'R-29']));

        self::assertStringContainsString("already has the SKU 'R-29'", $this->textOf($this->pageOf($response)));
        self::assertCount(1, $this->products()->all());
        self::assertNull($this->registry()->find('bikes/mountain/scree-27'), 'an address was left behind without its product');
    }

    public function testAnAddressTakenInACategoryIsRefusedOnTheForm(): void
    {
        $this->signedInHolding(['app:*']);
        $this->submit('add', $this->values());

        $response = $this->submit('add', $this->values(['name' => 'Ridge 29, again']));

        self::assertStringContainsString('is already the address of something else', $this->textOf($this->pageOf($response)));
        self::assertCount(1, $this->products()->all());
    }

    /** The list is what somebody keeping the catalogue comes back to: both prices, the state and where it answers. */
    public function testTheListShowsEachProductWithItsPricesAndItsPermalink(): void
    {
        $this->signedInHolding(['app:*']);
        $this->submit('add', $this->values());
        $id = $this->onlyProduct()->id();

        $document = $this->pageOf($this->submit('default', []));

        self::assertSame('Ridge 29', $this->testIdText($document, 'shop-product-open-' . $id));
        self::assertSame('24,990.00 CZK', $this->testIdText($document, 'shop-product-price-' . $id));
        self::assertSame('30,237.90 CZK', $this->testIdText($document, 'shop-product-gross-' . $id));
        self::assertSame('Draft', $this->testIdText($document, 'shop-product-status-' . $id));
        self::assertSame('/bikes/mountain/ridge-29', $this->testIdText($document, 'shop-product-address-' . $id));
    }

    public function testEditingFilesTheProductAsTheFormSays(): void
    {
        $this->signedInHolding(['app:*']);
        $this->submit('add', $this->values());
        $product = $this->onlyProduct();

        $this->submit(
            'edit',
            $this->values(['category' => $this->category['sale'], 'also' => [$this->category['mountain']], 'status' => 'published']),
            ['id' => (string) $product->id()],
        );

        $product = $this->onlyProduct();
        self::assertSame('sale/ridge-29', $this->products()->permalinkOf($product));
        self::assertSame(['sale/ridge-29', 'bikes/mountain/ridge-29'], $this->pathsOf($product));
        self::assertTrue($product->isPublished());
    }

    public function testTheOwnerMayChangeThePrice(): void
    {
        $this->signedInHolding(['app:*']);
        $product = $this->ridge();

        $this->submit('edit', $this->values(['price' => '19 990,50', 'vatRate' => '12']), ['id' => (string) $product->id()]);

        self::assertSame(1999050, $this->onlyProduct()->price()->amount());
        self::assertSame(1200, $this->onlyProduct()->vatRate()->basisPoints());
    }

    public function testDeletingAProductGivesItsAddressesBack(): void
    {
        $this->signedInHolding(['app:*']);
        $product = $this->ridge();

        $response = $this->submit('edit', ['delete' => 'Delete this product'], ['id' => (string) $product->id()]);

        self::assertInstanceOf(RedirectResponse::class, $response, $this->saidIn($response));
        self::assertSame([], $this->products()->all());
        self::assertNull($this->registry()->find('bikes/mountain/ridge-29'));
    }

    /** The role in between is shown what a product costs, in fields it cannot write in. */
    public function testTheRoleInBetweenIsShownThePriceAndCannotWriteInIt(): void
    {
        $this->signedInHolding($this->writer());
        $product = $this->ridge();

        $document = $this->pageOf($this->submit('edit', [], ['id' => (string) $product->id()]));

        $price = $document->querySelector('input[name="price"]');
        self::assertNotNull($price, 'the price is not shown at all');
        self::assertTrue($price->hasAttribute('disabled'), 'the role in between may write in the price');
        self::assertSame('24990.00', $price->getAttribute('value'));
        self::assertTrue($document->querySelector('input[name="vatRate"]')?->hasAttribute('disabled') ?? false);
    }

    /** A form sent with a price in it anyway - written by hand, or with the attribute taken off - changes the rest and not the price. */
    public function testAPriceSentByTheRoleInBetweenIsNotTaken(): void
    {
        $this->signedInHolding($this->writer());
        $product = $this->ridge();

        $response = $this->submit(
            'edit',
            $this->values(['name' => 'Ridge 29 Pro', 'price' => '1.00', 'vatRate' => '0']),
            ['id' => (string) $product->id()],
        );

        self::assertInstanceOf(RedirectResponse::class, $response, $this->saidIn($response));
        $product = $this->onlyProduct();
        self::assertSame('Ridge 29 Pro', $product->name(), 'the rest of the form was not taken either');
        self::assertSame(2499000, $product->price()->amount());
        self::assertSame(2100, $product->vatRate()->basisPoints());
    }

    /** A product the role in between writes starts at no price and at the installation's rate, whatever the form said. */
    public function testANewProductOfTheRoleInBetweenStartsAtNoPrice(): void
    {
        $this->signedInHolding($this->writer());

        $response = $this->submit('add', $this->values(['price' => '1.00', 'vatRate' => '0']));

        self::assertInstanceOf(RedirectResponse::class, $response, $this->saidIn($response));
        self::assertSame(0, $this->onlyProduct()->price()->amount());
        self::assertSame(2100, $this->onlyProduct()->vatRate()->basisPoints());
    }

    /**
     * The same form, sent to the action that lists: the form belongs to the
     * actions that draw it, so it is not found there at all - and the handler
     * would still ask for itself if it were.
     */
    public function testAFormSentToTheListByTheRoleInBetweenIsNotFound(): void
    {
        $this->signedInHolding($this->writer());

        self::assertSame(404, $this->refusalOf(fn(): Response => $this->submit('default', $this->values(['price' => '1.00', 'vatRate' => '0']))));
        self::assertSame([], $this->products()->all(), 'a product was written through a form sent to the list');
    }

    public function testDeletingIsRefusedToTheRoleInBetween(): void
    {
        $this->signedInHolding($this->writer());
        $product = $this->ridge();

        $response = $this->submit('edit', ['delete' => 'Delete this product'], ['id' => (string) $product->id()]);

        self::assertInstanceOf(ForwardResponse::class, $response, 'deleting was not refused');
        self::assertCount(1, $this->products()->all());
    }

    public function testTheFormIsRefusedToSomebodyWhoMayOnlyLook(): void
    {
        $this->signedInHolding([new Grant(ShopResource::Catalogue, Privilege::View)->code()]);

        self::assertInstanceOf(ForwardResponse::class, $this->submit('add', []));
    }

    /** A form sent to the list by somebody who may only look is not found, and writes nothing. */
    public function testAFormSentToTheListBySomebodyWhoMayOnlyLookIsNotFound(): void
    {
        $this->signedInHolding([new Grant(ShopResource::Catalogue, Privilege::View)->code()]);

        self::assertSame(404, $this->refusalOf(fn(): Response => $this->submit('default', $this->values())));
        self::assertSame([], $this->products()->all());
    }

    /**
     * The form belongs to the actions that draw it, `add` and `edit`, and to
     * no other - asked of the owner, who may do everything, so that nothing
     * but where the form belongs can be what refuses it.
     */
    public function testTheProductFormSentToTheListIsNotFoundEvenForTheOwner(): void
    {
        $this->signedInHolding(['app:*']);

        self::assertSame(404, $this->refusalOf(fn(): Response => $this->submit('default', $this->values())));
        self::assertSame([], $this->products()->all(), 'a product was written through a form sent to the list');
    }

    /** An action nobody wrote is no way into the form either, and a product it names stays as it was. */
    public function testTheProductFormSentToAMadeUpActionIsNotFound(): void
    {
        $this->signedInHolding(['app:*']);
        $product = $this->ridge();

        self::assertSame(404, $this->refusalOf(fn(): Response => $this->submit(
            'rewrite',
            $this->values(['name' => 'Ridge 29, rewritten', 'price' => '1.00']),
            ['id' => (string) $product->id()],
        )));

        $read = $this->onlyProduct();
        self::assertSame('Ridge 29', $read->name());
        self::assertSame(2499000, $read->price()->amount());
    }

    /**
     * The HTTP code a request was refused with by raising - a page that is not
     * there - rather than answered; failing when it was answered.
     *
     * @param \Closure(): Response $request
     */
    private function refusalOf(\Closure $request): int
    {
        try {
            $response = $request();
        } catch (BadRequestException $refused) {
            return $refused->getHttpCode();
        }

        self::fail('the request was answered, not refused: ' . $this->saidIn($response));
    }

    /** @return list<string> the role in between: writes the catalogue, changes no price, deletes nothing */
    private function writer(): array
    {
        return [
            new Grant(ShopResource::Catalogue, Privilege::View)->code(),
            new Grant(ShopResource::Catalogue, Privilege::Add)->code(),
            new Grant(ShopResource::Catalogue, Privilege::Edit)->code(),
        ];
    }

    /** A product written past the form, the way somebody else in the business wrote it earlier. */
    private function ridge(): Product
    {
        $product = $this->products()->create(
            'Ridge 29',
            new Filing($this->category['mountain'], [], 'ridge-29'),
            new Money(2499000, 'CZK'),
            new VatRate(2100),
        );
        $this->products()->describe($product, 'Ridge 29', null, 'A hardtail.', 'For the hills.');

        return $product;
    }

    /**
     * @param array<string, string|list<string>> $overrides
     *
     * @return array<string, string|list<string>>
     */
    private function values(array $overrides = []): array
    {
        return [
            'name' => 'Ridge 29',
            'sku' => '',
            'category' => $this->category['mountain'],
            'segment' => 'ridge-29',
            'price' => '24990',
            'vatRate' => '21',
            'perex' => 'A hardtail.',
            'description' => 'For the hills.',
            'status' => 'draft',
            'send' => 'Save',
            ...$overrides,
        ];
    }

    /**
     * @param array<string, string|list<string>> $post
     * @param array<string, string> $parameters
     */
    private function submit(string $action, array $post, array $parameters = []): Response
    {
        $presenter = $this->container()->getByType(IPresenterFactory::class)->createPresenter(self::PRESENTER);
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        return $presenter->run(new Request(
            self::PRESENTER,
            $post === [] ? 'GET' : 'POST',
            ['action' => $action, ...($post === [] ? [] : ['do' => self::SUBMIT]), ...$parameters],
            $post,
        ));
    }

    private function pageOf(Response $response): HTMLDocument
    {
        self::assertInstanceOf(TextResponse::class, $response, 'a page was expected, and the answer was something else');
        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);

        return HTMLDocument::createFromString((string) $source, LIBXML_NOERROR);
    }

    private function textOf(HTMLDocument $document): string
    {
        return (string) $document->body?->textContent;
    }

    private function testIdText(HTMLDocument $document, string $testId): string
    {
        return trim((string) $document->querySelector(sprintf('[data-testid="%s"]', $testId))?->textContent);
    }

    /** What a page said, for the message of an assertion that expected no page. */
    private function saidIn(Response $response): string
    {
        if (!$response instanceof TextResponse) {
            return 'the answer was a ' . $response::class;
        }

        $said = [];
        foreach ($this->pageOf($response)->querySelectorAll('.c-notice, .c-field__error') as $sentence) {
            $said[] = trim((string) $sentence->textContent);
        }

        return 'the form was drawn again, saying: ' . implode(' | ', $said);
    }

    /** @return list<string> */
    private function pathsOf(Product $product): array
    {
        return array_map(static fn(Address $address): string => $address->path, $this->products()->addressesOf($product));
    }

    private function onlyProduct(): Product
    {
        $this->container()->getByType(EntityManagerInterface::class)->clear();
        $products = $this->products()->all();
        self::assertCount(1, $products, 'the administration was expected to have written exactly one product');

        return $products[0];
    }

    private function products(): Products
    {
        return $this->container()->getByType(Products::class);
    }

    private function registry(): PathRegistry
    {
        return $this->container()->getByType(PathRegistry::class);
    }

    private function container(): Container
    {
        self::assertInstanceOf(Container::class, $this->container, 'nobody was signed in');

        return $this->container;
    }

    /**
     * A build with the shop and nothing else, a business with Bikes, Mountain
     * bikes under it and Sale beside it, and somebody signed in holding a role
     * of the business's own made of $pieces.
     *
     * @param list<string> $pieces
     */
    private function signedInHolding(array $pieces): void
    {
        $this->schema = Database::schemaFor(self::class);
        $container = Boot::container(ModuleList::of(
            ['cms' => false, 'crm' => false, 'shop' => true],
            Bootstrap::rootDirectory(),
        ));
        Migrations::run($container);
        Tenants::enter($container, 'Ammonite Bikes', Tenants::HOST);
        $this->container = $container;

        $categories = $container->getByType(Categories::class);
        $bikes = $categories->create('Bikes', 'bikes', null);
        $this->category = [
            'mountain' => $categories->create('Mountain bikes', 'mountain', $bikes->ref->id)->ref->id,
            'sale' => $categories->create('Sale', 'sale', null)->ref->id,
        ];

        $password = Random::generate(24, 'a-zA-Z0-9');
        $account = new User(
            'sam@example.com',
            $container->getByType(Passwords::class)->hash($password),
            'Sam Shopkeeper',
            new DateTimeImmutable('2026-09-14T08:00:00+00:00'),
        );
        $container->getByType(Accounts::class)->save($account);

        $business = $container->getByType(Tenancy::class)->tenant();
        $role = $pieces === ['app:*']
            ? new Role(Role::OWNER, 'Owner', $pieces)
            : Role::ofBusiness($business, 'catalogue', 'Catalogue', $pieces);
        $entityManager = $container->getByType(EntityManagerInterface::class);
        $entityManager->persist($role);
        $entityManager->persist(new Membership($business, $account, $role));
        $entityManager->flush();

        $container->getByType(SignedIn::class)->login('sam@example.com', $password);
    }
}
