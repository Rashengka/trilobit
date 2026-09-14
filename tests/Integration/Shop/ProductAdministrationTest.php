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
use Nette\Http\FileUpload;
use Nette\Http\IRequest;
use Nette\Http\Request as HttpRequest;
use Nette\Http\UrlScript;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\FileSystem;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Content\Categories;
use Trilobit\Core\Content\PathRegistry;
use Trilobit\Core\Domain\Media\MediaFile;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Media\UploadLimit;
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
use Trilobit\Tests\MediaDirectories;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Pictures;
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

    /** Where the pictures a test uploads are kept; see Trilobit\Tests\MediaDirectories. */
    private string $directory = '';

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
        MediaDirectories::delete($this->directory);
        $this->directory = '';

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

    /** The same form, sent to the action that lists: the handler asks for itself, because no action above it does. */
    public function testAFormSentToTheListByTheRoleInBetweenDoesNotReachThePriceEither(): void
    {
        $this->signedInHolding($this->writer());

        $this->submit('default', $this->values(['price' => '1.00', 'vatRate' => '0']));

        foreach ($this->products()->all() as $product) {
            self::assertSame(0, $product->price()->amount());
        }
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

    /** A form sent to the list by somebody who may only look writes nothing. */
    public function testAFormSentToTheListBySomebodyWhoMayOnlyLookIsRefused(): void
    {
        $this->signedInHolding([new Grant(ShopResource::Catalogue, Privilege::View)->code()]);

        $response = $this->submit('default', $this->values());

        self::assertInstanceOf(ForwardResponse::class, $response, 'the form was not refused');
        self::assertSame([], $this->products()->all());
    }

    public function testAPictureIsAddedFromTheEditPageWithWhatItShows(): void
    {
        $this->signedInHolding(['app:*']);
        $product = $this->ridge();

        $response = $this->sendPicture($product, $this->upload(Pictures::jpeg(40, 30), 'side.jpg'), 'The Ridge 29 from the side');

        self::assertInstanceOf(RedirectResponse::class, $response, $this->saidIn($response));
        $pictures = $this->products()->picturesOf($product);
        self::assertCount(1, $pictures);
        self::assertSame('The Ridge 29 from the side', $pictures[0]->file()->alt());
    }

    /** Every picture is drawn from its published variants, never from the original, which is not served. */
    public function testTheEditPageShowsEachPictureInItsVariants(): void
    {
        $this->signedInHolding(['app:*']);
        $product = $this->ridge();
        $picture = $this->products()->addPicture($product, $this->upload(Pictures::jpeg(40, 30), 'side.jpg')->getTemporaryFile(), 'side.jpg', 'From the side');

        $document = $this->pageOf($this->submit('edit', [], ['id' => (string) $product->id()]));

        $image = $document->querySelector(sprintf('[data-testid="shop-product-picture-image-%d"]', $picture->id()));
        self::assertNotNull($image, 'the picture is not drawn on the product\'s page');
        self::assertStringEndsWith('-thumb.jpg', (string) $image->getAttribute('src'));
        self::assertStringContainsString(' 40w', (string) $image->getAttribute('srcset'));
        self::assertSame('From the side', $image->getAttribute('alt'));
    }

    public function testTheListShowsTheFirstPictureOfAProduct(): void
    {
        $this->signedInHolding(['app:*']);
        $product = $this->ridge();
        $this->products()->addPicture($product, $this->upload(Pictures::jpeg(40, 30), 'side.jpg')->getTemporaryFile(), 'side.jpg', '');

        $document = $this->pageOf($this->submit('default', []));

        $thumb = $document->querySelector(sprintf('[data-testid="shop-product-thumb-%d"]', $product->id()));
        self::assertNotNull($thumb, 'the list shows no picture of the product');
        self::assertStringEndsWith('-thumb.jpg', (string) $thumb->getAttribute('src'));
    }

    /** PHP turned the file away before the application saw it; the form says so, and how large a file may be. */
    public function testAFileLargerThanTheServerTakesIsRefusedWithASentence(): void
    {
        $this->signedInHolding(['app:*']);
        $product = $this->ridge();
        $refused = new FileUpload(['name' => 'huge.jpg', 'size' => 0, 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE]);

        $document = $this->pageOf($this->sendPicture($product, $refused, ''));

        self::assertSame('true', $document->querySelector('#frm-pictures-picture')?->getAttribute('aria-invalid'));
        self::assertStringContainsString('larger than this server takes', $this->textOf($document));
        self::assertSame([], $this->products()->picturesOf($product));
    }

    public function testAFileThatIsNotAPictureIsRefusedBesideTheField(): void
    {
        $this->signedInHolding(['app:*']);
        $product = $this->ridge();

        $document = $this->pageOf($this->sendPicture($product, $this->upload('<svg xmlns="http://www.w3.org/2000/svg"/>', 'logo.svg'), ''));

        self::assertSame('true', $document->querySelector('#frm-pictures-picture')?->getAttribute('aria-invalid'));
        self::assertStringContainsString('is not a JPEG, PNG or WebP picture', $this->textOf($document));
        self::assertSame([], $this->products()->picturesOf($product));
    }

    /**
     * A request larger than post_max_size arrives with its body thrown away -
     * no fields, no file, and no signal saying a form was sent - and without
     * this the page would simply be drawn again as if nothing had been pressed.
     */
    public function testABodyTheServerThrewAwayIsSaidOnThePage(): void
    {
        $this->signedInHolding(['app:*']);
        $product = $this->ridge();
        $this->arrivingWith((string) (8 * 1024 * 1024 * 1024));

        $document = $this->pageOf($this->submit('edit', [], ['id' => (string) $product->id()], method: 'POST'));

        self::assertStringContainsString('larger than this server takes at once', $this->testIdText($document, 'shop-product-body-dropped'));
    }

    /** An empty form sent within the limit is not mistaken for one the server threw away. */
    public function testAnEmptyFormIsNotMistakenForOneThrownAway(): void
    {
        $this->signedInHolding(['app:*']);
        $product = $this->ridge();
        $this->arrivingWith('120');

        $document = $this->pageOf($this->submit('edit', [], ['id' => (string) $product->id()], method: 'POST'));

        self::assertNull($document->querySelector('[data-testid="shop-product-body-dropped"]'));
    }

    public function testPicturesAreRefusedToSomebodyWhoMayOnlyLook(): void
    {
        $this->signedInHolding([new Grant(ShopResource::Catalogue, Privilege::View)->code()]);
        $product = $this->ridge();

        $response = $this->sendPicture($product, $this->upload(Pictures::jpeg(40, 30), 'side.jpg'), '');

        self::assertInstanceOf(ForwardResponse::class, $response, 'the picture was not refused');
        self::assertSame([], $this->products()->picturesOf($product));
    }

    /** The pictures are a product's; a picture form sent to the list has no product to go to. */
    public function testAPictureFormSentToTheListIsNotFound(): void
    {
        $this->signedInHolding($this->writer());
        $this->ridge();

        $this->expectException(BadRequestException::class);

        $this->submit('default', ['alt' => '', 'upload' => 'Add the picture'], [], ['picture' => $this->upload(Pictures::jpeg(40, 30), 'side.jpg')], 'pictures-submit');
    }

    /** Decision Q4: taking a picture off a product takes the binding and leaves the file. */
    public function testRemovingAPictureTakesItOffAndLeavesTheFile(): void
    {
        $this->signedInHolding(['app:*']);
        $product = $this->ridge();
        $picture = $this->products()->addPicture($product, $this->upload(Pictures::jpeg(40, 30), 'side.jpg')->getTemporaryFile(), 'side.jpg', '');

        $response = $this->removePicture($product, (int) $picture->id());

        self::assertInstanceOf(RedirectResponse::class, $response, $this->saidIn($response));
        self::assertSame([], $this->products()->picturesOf($product));
        self::assertCount(1, $this->container()->getByType(EntityManagerInterface::class)->getRepository(MediaFile::class)->findAll());
    }

    /** A number sent by a form reaches a picture only through the product it is of. */
    public function testAPictureOfAnotherProductIsNotRemovedThroughThisOne(): void
    {
        $this->signedInHolding(['app:*']);
        $ridge = $this->ridge();
        $picture = $this->products()->addPicture($ridge, $this->upload(Pictures::jpeg(40, 30), 'side.jpg')->getTemporaryFile(), 'side.jpg', '');
        $scree = $this->products()->create('Scree 27', new Filing($this->category['mountain'], [], 'scree-27'), new Money(1999000, 'CZK'), new VatRate(2100));

        try {
            $this->removePicture($scree, (int) $picture->id());
            self::fail('a picture of another product was reached');
        } catch (BadRequestException $notFound) {
            self::assertSame(404, $notFound->getHttpCode());
        }

        self::assertCount(1, $this->products()->picturesOf($ridge));
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
     * @param array<string, FileUpload> $files
     * @param string|null $method a request's own method, for one whose body did not arrive
     */
    private function submit(
        string $action,
        array $post,
        array $parameters = [],
        array $files = [],
        string $signal = self::SUBMIT,
        ?string $method = null,
    ): Response {
        $presenter = $this->container()->getByType(IPresenterFactory::class)->createPresenter(self::PRESENTER);
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        $sent = $post !== [] || $files !== [];

        return $presenter->run(new Request(
            self::PRESENTER,
            $method ?? ($sent ? 'POST' : 'GET'),
            ['action' => $action, ...($sent ? ['do' => $signal] : []), ...$parameters],
            $post,
            $files,
        ));
    }

    /** The form for pictures of $product, sent with $upload in it. */
    private function sendPicture(Product $product, FileUpload $upload, string $alt): Response
    {
        return $this->submit(
            'edit',
            ['alt' => $alt, 'upload' => 'Add the picture'],
            ['id' => (string) $product->id()],
            ['picture' => $upload],
            'pictures-submit',
        );
    }

    /** The button taking picture $id off $product. */
    private function removePicture(Product $product, int $id): Response
    {
        return $this->submit(
            'edit',
            ['remove' => 'Take it off'],
            ['id' => (string) $product->id()],
            [],
            sprintf('removePicture-%d-submit', $id),
        );
    }

    /** A file holding $bytes, as an upload arrives: under a temporary name, with the name it came with beside it. */
    private function upload(string $bytes, string $name): FileUpload
    {
        $file = $this->directory . '/uploads/' . bin2hex(random_bytes(6));
        FileSystem::write($file, $bytes);

        return new FileUpload(['name' => $name, 'size' => strlen($bytes), 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK]);
    }

    /**
     * The request the next page is drawn for, as a server with a 2 MB limit on
     * a file and 8 MB on a request received it: sent, saying it carried
     * $length bytes, and with nothing of them in it - which is what PHP leaves
     * of a body it threw away.
     *
     * The limits are stated rather than read from the PHP running the suite,
     * which may have none, and then no body is ever too long for it.
     */
    private function arrivingWith(string $length): void
    {
        $container = $this->container();

        $request = $container->findByType(IRequest::class)[0] ?? self::fail('the build has no HTTP request');
        $container->removeService($request);
        $container->addService($request, new HttpRequest(
            new UrlScript('http://localhost/admin/shop/products'),
            headers: ['Content-Length' => $length],
            method: 'POST',
        ));

        $limit = $container->findByType(UploadLimit::class)[0] ?? self::fail('the build has no upload limit');
        $container->removeService($limit);
        $container->addService($limit, new UploadLimit(2 * 1024 * 1024, 8 * 1024 * 1024));
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
        $this->directory = MediaDirectories::temporaryFor($container);
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
