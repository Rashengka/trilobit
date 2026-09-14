<?php

declare(strict_types=1);

namespace Trilobit\Shop\DI;

use Nette\DI\CompilerExtension;
use Trilobit\Core\DI\CoreExtension;
use Trilobit\Shop\Admin\ShopMenu;
use Trilobit\Shop\Application\Product\PriceSettings;
use Trilobit\Shop\Application\Product\Products;
use Trilobit\Shop\Domain\Product\ProductRepository;
use Trilobit\Shop\Infrastructure\Doctrine\DoctrineProductRepository;
use Trilobit\Shop\Presentation\Admin\ProductListingFactory;
use Trilobit\Shop\Presentation\Front\ShopSignpost;
use Trilobit\Shop\Routing\ShopRoutes;
use Trilobit\Shop\Security\ShopResources;
use Trilobit\Shop\Seed\ShopSeed;

/**
 * Everything the Shop module puts into the container.
 *
 * The catalogue is here - products, their prices and where they are filed -
 * and the cart and orders arrive later (.ai/plans/03-poradi-praci.md, T09 to
 * T11). Nothing below is conditional: the extension is either registered by
 * the boot or it is not, and a module the boot did not register contributes
 * nothing at all.
 *
 * **The administration menu entry is the catalogue's**, and it is here because
 * the catalogue is an administration page to lead to - the rule every module
 * keeps. The categories products are filed in have no entry of this module's:
 * they are Core's, and arranged wherever the content of the site is.
 *
 * The tags go on in loadConfiguration() rather than in beforeCompile(), because
 * Core reads them in its own beforeCompile(). Extensions all load before any of
 * them compile, so tagging early is the ordering that is guaranteed to work,
 * and tagging late is the ordering that works until somebody changes the order
 * modules are registered in.
 *
 * The tagged services are not autowired. Nothing asks for a route provider by
 * its type - Core collects them by tag - and leaving three modules offering the
 * same interface to autowiring would only make an ambiguity for somebody to
 * trip over later. The repository is autowired, because this module's own
 * classes do ask for it by type.
 */
final class ShopExtension extends CompilerExtension
{
    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();

        $builder->addDefinition($this->prefix('routes'))
            ->setFactory(ShopRoutes::class)
            ->setAutowired(false)
            ->addTag(CoreExtension::TAG_ROUTE_PROVIDER);

        $builder->addDefinition($this->prefix('signpost'))
            ->setFactory(ShopSignpost::class)
            ->setAutowired(false)
            ->addTag(CoreExtension::TAG_SIGNPOST_PROVIDER);

        // What a permission question about the shop may be about. Core cannot
        // hold the shop's resources - it may not name a module - so they are
        // brought here, and a build without the shop has none of them.
        $builder->addDefinition($this->prefix('resources'))
            ->setFactory(ShopResources::class)
            ->setAutowired(false)
            ->addTag(CoreExtension::TAG_RESOURCE_PROVIDER);

        // Where products are kept. The interface is what the rest of the
        // module names, and the implementation is the only place in it that
        // knows Doctrine exists.
        $builder->addDefinition($this->prefix('productRepository'))
            ->setType(ProductRepository::class)
            ->setFactory(DoctrineProductRepository::class);

        // What the installation says about prices, from the parameters in
        // src/Shop/config/services.neon, which config/local.neon may override.
        $builder->addDefinition($this->prefix('priceSettings'))
            ->setFactory(PriceSettings::class, $this->priceParameters());

        // The one place that knows a product is a row here and as many rows of
        // Core's register as it has categories.
        $builder->addDefinition($this->prefix('products'))
            ->setFactory(Products::class);

        $builder->addDefinition($this->prefix('adminMenu'))
            ->setFactory(ShopMenu::class)
            ->setAutowired(false)
            ->addTag(CoreExtension::TAG_ADMIN_MENU_PROVIDER);

        // The list of products in the administration, made by a factory the
        // container writes, so the presenter asks for the list rather than
        // for everything the list is made of.
        $builder->addFactoryDefinition($this->prefix('productListing'))
            ->setImplement(ProductListingFactory::class);

        // What `app:seed` gives every business it makes on a working copy: a
        // catalogue, and somebody who keeps it and may not change a price.
        $builder->addDefinition($this->prefix('seed'))
            ->setFactory(ShopSeed::class)
            ->setAutowired(false)
            ->addTag(CoreExtension::TAG_SEED_PROVIDER);
    }

    /**
     * The currency and the rate a new product starts with, as the
     * configuration says them - refused while the container is compiled when
     * they are not a string and a whole number, so that a mistyped parameter
     * stops the build rather than the first product saved.
     *
     * @return array{string, int}
     */
    private function priceParameters(): array
    {
        $shop = $this->getContainerBuilder()->parameters['shop'] ?? null;
        $currency = is_array($shop) ? ($shop['currency'] ?? null) : null;
        $rate = is_array($shop) ? ($shop['vatRate'] ?? null) : null;

        if (!is_string($currency) || !is_int($rate)) {
            throw new \LogicException(
                'shop.currency has to be the three letters of a currency and shop.vatRate a whole number of basis '
                . 'points; see src/Shop/config/services.neon.',
            );
        }

        return [$currency, $rate];
    }
}
