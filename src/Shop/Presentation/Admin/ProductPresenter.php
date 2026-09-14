<?php

declare(strict_types=1);

namespace Trilobit\Shop\Presentation\Admin;

use Nette\Application\UI\Form;
use Nette\Application\UI\Multiplier;
use Nette\Application\UI\Template;
use Nette\Forms\Controls\BaseControl;
use Nette\Http\FileUpload;
use Trilobit\Core\Content\Categories;
use Trilobit\Core\Content\PathRefused;
use Trilobit\Core\Media\MediaLibrary;
use Trilobit\Core\Media\UploadLimit;
use Trilobit\Core\Media\UploadRefused;
use Trilobit\Core\Presentation\Admin\AdminPresenter;
use Trilobit\Core\Presentation\Error\RefusalPresenter;
use Trilobit\Core\Presentation\Form\FormFactory;
use Trilobit\Core\Security\Needs;
use Trilobit\Core\Security\Privilege;
use Trilobit\Shop\Application\Product\Filing;
use Trilobit\Shop\Application\Product\ProductRefused;
use Trilobit\Shop\Application\Product\Products;
use Trilobit\Shop\Domain\Price\Money;
use Trilobit\Shop\Domain\Price\PriceRefused;
use Trilobit\Shop\Domain\Price\VatRate;
use Trilobit\Shop\Domain\Product\Product;
use Trilobit\Shop\Domain\Product\ProductImage;
use Trilobit\Shop\Domain\Product\ProductStatus;
use Trilobit\Shop\Security\ShopResource;

/**
 * The catalogue: the list of products, and the form one is written in
 * (.ai/plans/30-obchod-katalog-t09.md).
 *
 * **Where a product is filed is part of the form, and it is not a field of the
 * product.** It lives in Core's register, one address per category (decision
 * R12), so the form asks for the main category - the permalink - the others,
 * and the last part of the address, and hands them to
 * Trilobit\Shop\Application\Product\Products, which writes the product and its
 * addresses together or not at all. Every refusal - an address taken, a price
 * nobody can read, an SKU another product has - comes back as a sentence on
 * the form. The categories are Core's, so this page lists them without knowing
 * which module arranges them.
 *
 * **What a product costs is a right of its own**, `app.administration.shop.price`
 * (decision Q1), a sibling of the catalogue so that it can be withheld. Somebody
 * who may write the catalogue and not change a price is shown the price and the
 * rate in fields they cannot write in, and the handler does not read either for
 * them - so a form sent with a price in it anyway, by hand or with the
 * attribute taken off, changes everything else and not what the product costs.
 * A product such a person adds starts at no price and at the installation's
 * rate, and waits for somebody who may set it.
 *
 * **What writing needs is asked twice, and the second time is the one that
 * holds.** The actions declare what they need, which keeps the pages from
 * opening; but a form arrives through processSignal(), which asks nothing of
 * any method, and it can be sent to any action of this presenter - to the
 * list, whose gate is only the right to look. So the handler asks for itself:
 * adding for a new product, editing for an existing one, deleting for the
 * button that deletes, and the price for the price. A refusal there is the
 * refusal page every gate forwards to, not an error.
 *
 * **Pictures are added and taken off on the product's own page** (decision Q4).
 * A picture goes into Core's media library, and taking it off removes only
 * the binding. PHP turns a file away before the application sees it in two
 * ways - over `upload_max_filesize` with an error code, over `post_max_size`
 * by throwing the whole body away - and both are said in a sentence: the first
 * beside the field, the second at the top of the page, which would otherwise
 * be drawn again as if nothing had been sent (Trilobit\Core\Media\UploadLimit).
 * Adding and taking off ask for editing the product, in their handlers too,
 * and a picture is reached only through the product it is of.
 *
 * **Deleting is a submit and never a link**, for the reason a page's is; and it
 * takes the product and every address it had, hard, until
 * .ai/plans/16-soft-delete.md gives the catalogue a bin.
 */
#[Needs(ShopResource::Catalogue, Privilege::View)]
final class ProductPresenter extends AdminPresenter
{
    private const string FORM = 'product';

    /** The list of products, by the name its parameters carry in the address: `?products-name=...`. */
    private const string LIST = 'products';

    private ?Product $edited = null;

    /** Whether PHP threw away the body of this request for its size; see UploadLimit::droppedBody(). */
    private bool $bodyDropped = false;

    public function __construct(
        private readonly Products $products,
        private readonly Categories $categories,
        private readonly FormFactory $forms,
        private readonly ProductListingFactory $listings,
        private readonly MediaLibrary $library,
        private readonly UploadLimit $uploadLimit,
    ) {
        parent::__construct();
    }

    /** A new product is written in the same form an existing one is; only what happens on save differs. */
    #[Needs(ShopResource::Catalogue, Privilege::Add)]
    public function actionAdd(): void
    {
        $this->setView('edit');
    }

    #[Needs(ShopResource::Catalogue, Privilege::Edit)]
    public function actionEdit(int $id): void
    {
        $product = $this->products->find($id);
        if (!$product instanceof Product) {
            $this->error('No such product.');
        }

        $this->edited = $product;

        // A picture too large for the server arrives as a request with nothing
        // in it, which reads like a page opened rather than a form sent; it is
        // told apart here and said on the page, with the status that means it.
        $request = $this->getRequest();
        $this->bodyDropped = $this->uploadLimit->droppedBody(
            $request->isMethod('POST'),
            $request->getPost() === [] && $request->getFiles() === [],
            $this->contentLength(),
        );
        if ($this->bodyDropped) {
            $this->getHttpResponse()->setCode(413);
        }

        $filing = $this->products->filingOf($product);
        $this->form()->setDefaults([
            'name' => $product->name(),
            'sku' => $product->sku() ?? '',
            'category' => $filing->main === '' ? null : $filing->main,
            'also' => $filing->also,
            'segment' => $filing->segment,
            'price' => $product->price()->decimal(),
            'vatRate' => $product->vatRate()->percent(),
            'perex' => $product->perex(),
            'description' => $product->description(),
            'status' => $product->status()->value,
        ]);
    }

    public function renderDefault(): void
    {
        $template = $this->template();

        $template->pageTitle = 'Products';
        $template->headline = 'Products';
        $template->lead = 'What this business sells, what it costs, and where each of it answers.';
        $template->addUrl = $this->link('add');

        // Made before the template is drawn, not by it: an answer to Naja
        // holds the snippets of the controls that exist by then.
        $this->getComponent(self::LIST);
    }

    public function renderEdit(): void
    {
        $template = $this->template();
        $product = $this->edited;

        $template->isNew = !$product instanceof Product;
        $template->pageTitle = $product instanceof Product ? $product->name() : 'A new product';
        $template->headline = $template->pageTitle;
        $template->lead = $template->isNew
            ? 'A product starts as a draft, and the addresses it will answer at are held for it from the moment it is saved.'
            : 'What this product is, where it is filed, what it costs, and whether a visitor may see it.';
        $template->listUrl = $this->link('default');
        $template->noCategory = $this->categories->all() === [];
        $template->addresses = $product instanceof Product ? $this->products->addressesOf($product) : [];
        $template->priceWithVat = $product instanceof Product ? $product->priceWithVat()->format() : '';

        $basePath = $this->getHttpRequest()->getUrl()->getBasePath();
        $template->pictures = $product instanceof Product
            ? array_map(
                fn(ProductImage $picture): PictureSummary => PictureSummary::of($picture, $this->library, $basePath),
                $this->products->picturesOf($product),
            )
            : [];
        $template->bodyDropped = $this->bodyDropped;
        $template->largestFile = $this->uploadLimit->describe();
    }

    /** See Trilobit\Core\Presentation\Admin\AdminPresenter::createTemplate(). */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? ProductsTemplate::class);
    }

    /**
     * The form a picture is added in - on the page of a product and nowhere
     * else. A picture sent to any other action of this presenter has no
     * product to go to, and is not found.
     */
    protected function createComponentPictures(): Form
    {
        if (!$this->edited instanceof Product) {
            $this->error('Pictures belong to a product, and no product is open.');
        }

        $form = $this->forms->createVertical();
        $form->getElementPrototype()->setAttribute('data-testid', 'shop-product-pictures-form');

        $picture = $form->addUpload('picture', 'Picture');
        // The control's own rules answer a file PHP turned away with a
        // sentence saying only that it is not valid; addPicture() says which
        // of the two it was, and how large a file may be.
        $picture->getRules()->reset();
        $picture->setRequired('Choose a picture to add.')
            ->setOption('description', sprintf('JPEG, PNG or WebP, at most %s.', $this->uploadLimit->describe()))
            ->setHtmlAttribute('accept', 'image/jpeg,image/png,image/webp')
            ->setHtmlAttribute('data-testid', 'shop-product-picture-input');
        $form->addText('alt', 'What it shows')
            ->setMaxLength(255)
            ->setOption('description', 'Said instead of the picture to somebody who cannot see it. Left empty, the picture is taken for decoration.')
            ->setHtmlAttribute('data-testid', 'shop-product-picture-alt');

        $form->addSubmit('upload', 'Add the picture')->setHtmlAttribute('data-testid', 'shop-product-picture-add');
        $form->onSuccess[] = $this->addPicture(...);

        return $form;
    }

    /**
     * The button taking one picture off the product, one small form each, so
     * that each button sends the picture it stands under and nothing else.
     *
     * @return Multiplier<Form>
     */
    protected function createComponentRemovePicture(): Multiplier
    {
        return new Multiplier(function (string $id): Form {
            $form = $this->forms->createInline(labelsShown: false);
            $remove = $form->addSubmit('remove', 'Take it off');
            $remove->setHtmlAttribute('class', 'c-button--danger');
            $remove->setHtmlAttribute('data-testid', 'shop-product-picture-remove-' . $id);
            $form->onSuccess[] = function () use ($id): void {
                $this->removePicture((int) $id);
            };

            return $form;
        });
    }

    /** Every product, filtered and paged from the address; see ProductListing. */
    protected function createComponentProducts(): ProductListing
    {
        return $this->listings->create();
    }

    protected function createComponentProduct(): Form
    {
        $form = $this->forms->createVertical();
        $form->getElementPrototype()->setAttribute('data-testid', 'shop-product-form');
        $categories = $this->categoryChoices();
        $prices = $this->products->prices();

        $form->addText('name', 'Name')
            ->setRequired('A product needs a name.')
            ->setMaxLength(Product::MAX_NAME_LENGTH)
            ->setHtmlAttribute('data-testid', 'shop-product-name-input');
        $form->addText('sku', 'SKU')
            ->setMaxLength(Product::MAX_SKU_LENGTH)
            ->setOption('description', 'Optional. No two products of this business have the same one.')
            ->setHtmlAttribute('data-testid', 'shop-product-sku-input');
        $form->addSelect('category', 'Main category', $categories)
            ->setPrompt('--- choose the main category')
            ->setRequired('A product is filed in at least one category; choose the main one.')
            ->setOption('description', 'Its permalink is in this category; a visitor may reach it from the others too.')
            ->setHtmlAttribute('data-combobox', true)
            ->setHtmlAttribute('data-testid', 'shop-product-category-input');
        $form->addCheckboxList('also', 'Also filed under', $categories);
        $form->addText('segment', 'Last part of the address')
            ->setOption('description', 'Lower case letters, digits and hyphens, the same in every category. Left empty, it is made from the name.')
            ->setHtmlAttribute('data-testid', 'shop-product-segment-input');

        $price = $form->addText('price', sprintf('Price before tax (%s)', $prices->currency))
            ->setRequired('A product needs a price; 0 is one.')
            ->setOption('description', 'Digits, with a dot or a comma before at most two decimal places.')
            ->setHtmlAttribute('data-testid', 'shop-product-price-input');
        $rate = $form->addText('vatRate', 'Rate of tax (%)')
            ->setRequired('A product needs a rate of tax; 0 is one.')
            ->setHtmlAttribute('data-testid', 'shop-product-vat-input');
        if (!$this->mayReprice()) {
            $this->showWithoutLettingChange($price, $rate);
        }

        $form->addTextArea('perex', 'Lead', null, 3);
        $form->addTextArea('description', 'Description', null, 8);
        $form->addSelect('status', 'State', [
            ProductStatus::Draft->value => 'Draft, nobody but you sees it',
            ProductStatus::Published->value => 'Published',
        ])->setDefaultValue(ProductStatus::Draft->value);

        $form->addSubmit('send', 'Save')->setHtmlAttribute('data-testid', 'shop-product-save');
        $form->onSuccess[] = $this->save(...);

        if ($this->edited instanceof Product) {
            // Its own handler, and nothing validated: a product is deleted
            // whether or not the form beside the button is filled in right.
            $delete = $form->addSubmit('delete', 'Delete this product');
            $delete->setValidationScope([]);
            $delete->setHtmlAttribute('class', 'c-button--danger');
            $delete->setHtmlAttribute('data-testid', 'shop-product-delete');
            $delete->onClick[] = $this->delete(...);
        } else {
            $form->setDefaults([
                'price' => $this->mayReprice() ? '' : $prices->nothing()->decimal(),
                'vatRate' => $prices->defaultRate->percent(),
            ]);
        }

        return $form;
    }

    /**
     * Writes the product, its addresses and - for somebody who may - its
     * price, or says on the form why not. Asks for itself first; see the class.
     */
    private function save(Form $form): void
    {
        $product = $this->edited;
        $mayWrite = $product instanceof Product
            ? $this->getUser()->isAllowed(ShopResource::Catalogue, Privilege::Edit)
            : $this->getUser()->isAllowed(ShopResource::Catalogue, Privilege::Add);
        if (!$mayWrite) {
            $this->forward(RefusalPresenter::DESTINATION);
        }

        $values = $form->getValues('array');
        $name = $this->text($values, 'name');
        $sku = $this->text($values, 'sku');
        $main = $this->choice($values, 'category') ?? '';

        // The price and the rate, for somebody who may set them; null for
        // everybody else, whatever the form carried.
        $repriced = null;
        if ($this->mayReprice()) {
            $price = $this->read($form, 'price', fn(string $written): Money => Money::ofDecimal($written, $this->products->prices()->currency));
            $rate = $this->read($form, 'vatRate', VatRate::ofPercent(...));
            if (!$price instanceof Money || !$rate instanceof VatRate) {
                return;
            }

            $repriced = [$price, $rate];
        }

        try {
            $refusal = $this->products->skuRefusal($sku, $product);
            if ($refusal instanceof ProductRefused) {
                throw $refusal;
            }

            $segment = $this->text($values, 'segment');
            $filing = new Filing(
                $main,
                $this->choices($values, 'also'),
                $segment !== '' ? $segment : $this->products->suggestSegment($name, $main, $product),
            );

            if (!$product instanceof Product) {
                $product = $this->products->create(
                    $name,
                    $filing,
                    $repriced[0] ?? $this->products->prices()->nothing(),
                    $repriced[1] ?? $this->products->prices()->defaultRate,
                );
            } else {
                $this->products->fileAs($product, $filing);
                if ($repriced !== null) {
                    $this->products->reprice($product, ...$repriced);
                }
            }

            $this->products->describe($product, $name, $sku, $this->text($values, 'perex'), $this->text($values, 'description'));

            if ($this->text($values, 'status') === ProductStatus::Published->value) {
                $this->products->publish($product);
            } else {
                $this->products->withdraw($product);
            }
        } catch (PathRefused | ProductRefused $refused) {
            // Written for whoever typed it; see Trilobit\Core\Content\PathRefused.
            $form->addError($refused->getMessage());

            return;
        }

        $this->redirect('default');
    }

    /**
     * Deleting asks for itself, here: the action it arrives through is `edit`,
     * and being trusted to rewrite a product is not being trusted to take it
     * away.
     */
    private function delete(): void
    {
        if (!$this->getUser()->isAllowed(ShopResource::Catalogue, Privilege::Delete)) {
            $this->forward(RefusalPresenter::DESTINATION);
        }

        $product = $this->edited
            ?? throw new \LogicException('The delete button is only added to the form while a product is being edited.');

        $this->products->delete($product);
        $this->redirect('default');
    }

    /**
     * Takes the picture into the library and puts it on the product, or says
     * beside the field why not: PHP turned it away for its size, it did not
     * arrive whole, or the library does not take it. A picture that could not
     * be kept for the server's reasons is not said here but raised, because
     * it is the server's to fix and its log's to say.
     */
    private function addPicture(Form $form): void
    {
        if (!$this->getUser()->isAllowed(ShopResource::Catalogue, Privilege::Edit)) {
            $this->forward(RefusalPresenter::DESTINATION);
        }

        $product = $this->edited
            ?? throw new \LogicException('The form for pictures is only made while a product is open.');
        $control = $form->getComponent('picture');
        $upload = $control instanceof BaseControl ? $control->getValue() : null;
        if (!$control instanceof BaseControl || !$upload instanceof FileUpload) {
            throw new \LogicException('The picture control holds one file.');
        }

        if (!$upload->isOk()) {
            $control->addError($this->uploadLimit->refusedForItsSize($upload->getError())
                ? sprintf('The file is larger than this server takes: a picture may have at most %s.', $this->uploadLimit->describe())
                : 'The file did not arrive whole. Send it again.');

            return;
        }

        $alt = $form->getComponent('alt');
        $written = $alt instanceof BaseControl ? $alt->getValue() : '';

        try {
            $this->products->addPicture(
                $product,
                $upload->getTemporaryFile(),
                $upload->getUntrustedName(),
                is_string($written) ? trim($written) : '',
            );
        } catch (UploadRefused $refused) {
            $control->addError($refused->getMessage());

            return;
        }

        $this->redirect('this');
    }

    /**
     * Takes picture $id off the product open on this page, and only the
     * binding. A number naming a picture of another product - or sent where
     * no product is open - is not found, whatever the form said.
     */
    private function removePicture(int $id): void
    {
        if (!$this->getUser()->isAllowed(ShopResource::Catalogue, Privilege::Edit)) {
            $this->forward(RefusalPresenter::DESTINATION);
        }

        $product = $this->edited;
        if (!$product instanceof Product || !$this->products->pictureOf($product, $id) instanceof ProductImage) {
            $this->error('This product has no such picture.');
        }

        $this->products->removePicture($product, $id);
        $this->redirect('this');
    }

    /** How long the request said its body was, or null where it said nothing that is a length. */
    private function contentLength(): ?int
    {
        $length = $this->getHttpRequest()->getHeader('Content-Length');

        return $length !== null && ctype_digit($length) ? (int) $length : null;
    }

    /** Whether the person making this request may change what a product costs and the rate of tax on it. */
    private function mayReprice(): bool
    {
        return $this->getUser()->isAllowed(ShopResource::Price, Privilege::Edit);
    }

    /**
     * The price and the rate, drawn and not written in: a disabled control is
     * one the framework does not read back, and the handler does not ask for
     * it either - the second is the one that holds, see the class.
     */
    private function showWithoutLettingChange(BaseControl ...$controls): void
    {
        foreach ($controls as $control) {
            $control->setDisabled()
                ->setOption('description', 'Only somebody who may change prices can change this.');
        }
    }

    /**
     * What $field holds, read by $read - or null, with the sentence the read
     * refused it with written beside the field.
     *
     * @template T of object
     *
     * @param \Closure(string): T $read
     *
     * @return T|null
     */
    private function read(Form $form, string $field, \Closure $read): ?object
    {
        $control = $form->getComponent($field);
        $value = $control instanceof BaseControl ? $control->getValue() : null;

        try {
            return $read(is_string($value) ? $value : '');
        } catch (PriceRefused $refused) {
            if ($control instanceof BaseControl) {
                $control->addError($refused->getMessage());
            }

            return null;
        }
    }

    /**
     * Every category a product may be filed in, by its identifier, named with
     * its address so that two called the same are told apart.
     *
     * @return array<string, string>
     */
    private function categoryChoices(): array
    {
        $choices = [];
        foreach ($this->categories->all() as $category) {
            $choices[$category->ref->id] = $category->label . ' (/' . $category->path . ')';
        }

        return $choices;
    }

    /** The form on this page; its type is read off createComponentProduct() by the analyser. */
    private function form(): Form
    {
        return $this->getComponent(self::FORM);
    }

    private function template(): ProductsTemplate
    {
        $template = $this->getTemplate();
        if (!$template instanceof ProductsTemplate) {
            throw new \LogicException(sprintf('The template of %s has to be a %s.', self::class, ProductsTemplate::class));
        }

        return $template;
    }

    /**
     * One value of the submitted form as a string, trimmed; see
     * Trilobit\Cms\Presentation\Admin\PagePresenter::text() for why it is
     * not taken on trust.
     *
     * @param array<string, mixed> $values
     */
    private function text(array $values, string $field): string
    {
        $value = $values[$field] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * What was chosen in a select with a prompt, or null for the prompt. An
     * identifier made of digits alone comes back as a number, and is turned
     * back into the string it is.
     *
     * @param array<string, mixed> $values
     */
    private function choice(array $values, string $field): ?string
    {
        $value = $values[$field] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    /**
     * What was ticked in a list of boxes, as strings; see choice().
     *
     * @param array<string, mixed> $values
     *
     * @return list<string>
     */
    private function choices(array $values, string $field): array
    {
        $value = $values[$field] ?? [];
        $chosen = [];
        foreach (is_array($value) ? $value : [] as $one) {
            if (is_string($one) || is_int($one)) {
                $chosen[] = (string) $one;
            }
        }

        return $chosen;
    }
}
