<?php

declare(strict_types=1);

namespace Trilobit\Shop\Presentation\Admin;

use Trilobit\Core\Content\Address;
use Trilobit\Core\Presentation\Admin\AdminTemplate;

/**
 * What both views of Shop:Admin:Product render with: the list of products, and
 * the form one product is written in.
 *
 * One class for two views, because the two are one job and the presenter hands
 * Latte one class. Whichever view is drawn reads the properties that mean
 * something to it; the others keep their empty defaults.
 */
final class ProductsTemplate extends AdminTemplate
{
    public string $headline = '';

    public string $lead = '';

    public string $addUrl = '';

    public string $listUrl = '';

    public bool $isNew = true;

    /** Whether there is no category at all yet, which a product has to be filed in. */
    public bool $noCategory = false;

    /** @var list<Address> every address the product answers at, the permalink first */
    public array $addresses = [];

    /** What it costs with tax, as it is shown; '' while a new product is being written. */
    public string $priceWithVat = '';
}
