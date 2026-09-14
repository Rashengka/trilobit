<?php

declare(strict_types=1);

namespace Trilobit\Shop\Presentation\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Trilobit\Core\Presentation\Form\FormFactory;
use Trilobit\Core\Presentation\Listing\Comparison;
use Trilobit\Core\Presentation\Listing\Filters;
use Trilobit\Core\Presentation\Listing\Listing;
use Trilobit\Shop\Application\Product\Products;
use Trilobit\Shop\Domain\Product\Product;
use Trilobit\Shop\Domain\Product\ProductStatus;

/**
 * Every product of the business, filtered by its name, its SKU and its state,
 * and paged - on Trilobit\Core\Presentation\Listing\Listing, the way the list
 * of pages is.
 *
 * The name and the SKU are looked for anywhere in them, because somebody
 * looking for a product remembers a word or a part of a code rather than how
 * it begins. The categories are not a filter: where a product is filed is rows
 * of Core's register rather than a field of the product, and a filter narrows
 * a field of the entity it lists.
 *
 * The query reads shop_product and nothing else, so Core's tenant filter
 * scopes it as it scopes every read of that table.
 */
final class ProductListing extends Listing
{
    public function __construct(
        FormFactory $forms,
        private readonly EntityManagerInterface $entityManager,
        private readonly Products $products,
    ) {
        parent::__construct($forms);
    }

    protected function configure(Filters $filters): void
    {
        $filters
            ->text('name', 'Name', Comparison::Contains)
            ->text('sku', 'SKU', Comparison::Contains)
            ->choice(
                'status',
                'State',
                [ProductStatus::Draft->value => 'Draft', ProductStatus::Published->value => 'Published'],
                Comparison::Equals,
                prompt: 'Any state',
            );
    }

    protected function query(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('product')
            ->from(Product::class, 'product')
            ->orderBy('product.name', 'ASC');
    }

    /**
     * @param list<object> $items
     *
     * @return list<ProductSummary>
     */
    protected function rows(array $items): array
    {
        $presenter = $this->getPresenter();

        $summaries = [];
        foreach ($items as $product) {
            $id = $product instanceof Product ? $product->id() : null;
            if (!$product instanceof Product || $id === null) {
                continue;
            }

            $permalink = $this->products->permalinkOf($product);

            $summaries[] = new ProductSummary(
                $id,
                $product->name(),
                $product->sku() ?? '',
                $product->price()->format(),
                $product->priceWithVat()->format(),
                $product->isPublished() ? 'Published' : 'Draft',
                $product->isPublished(),
                // Said in words where there is none: an empty cell beside a
                // product would read as a product at the root of the site.
                $permalink === null ? 'no address yet' : '/' . $permalink,
                $presenter->link('edit', ['id' => $id]),
            );
        }

        return $summaries;
    }

    protected function rowsTemplate(): string
    {
        return __DIR__ . '/templates/ProductListing.latte';
    }

    protected function caption(): string
    {
        return 'Every product, what it costs and where it answers';
    }

    protected function counted(int $count): string
    {
        return $count === 1 ? '1 product' : sprintf('%d products', $count);
    }

    protected function nothingYet(): string
    {
        return 'Nothing is for sale yet.';
    }

    protected function nothingMatches(): string
    {
        return 'No product matches the filters.';
    }

    protected function testId(): string
    {
        return 'shop-product-list';
    }
}
