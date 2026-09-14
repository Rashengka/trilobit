<?php

declare(strict_types=1);

namespace Trilobit\Shop\Domain\Product;

/**
 * Whether a product is something a visitor may see.
 *
 * Two states, for the reason a page has two (see
 * Trilobit\Cms\Domain\Page\PageStatus, which this module may not name but may
 * agree with): the question a request asks is yes or no. Out of stock, coming
 * soon and discontinued are things a shop will want to say about a product,
 * and each is a fact about the product rather than a third answer to whether
 * it is shown - **exit condition:** the first of them somebody asks for.
 */
enum ProductStatus: string
{
    /** Being written; its addresses are held for it all the same. */
    case Draft = 'draft';

    case Published = 'published';
}
