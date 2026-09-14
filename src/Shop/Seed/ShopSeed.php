<?php

declare(strict_types=1);

namespace Trilobit\Shop\Seed;

use Trilobit\Core\Content\Categories;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Security\Grant;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Seed\SeededMember;
use Trilobit\Core\Seed\SeedProvider;
use Trilobit\Core\Seed\SeedsMembers;
use Trilobit\Shop\Application\Product\Filing;
use Trilobit\Shop\Application\Product\Products;
use Trilobit\Shop\Domain\Price\Money;
use Trilobit\Shop\Domain\Price\VatRate;
use Trilobit\Shop\Security\ShopResource;

/**
 * The catalogue `app:seed` gives every business it makes, and somebody who
 * keeps it (.ai/plans/30-obchod-katalog-t09.md, V3).
 *
 * What is here is what clicking through the catalogue has to meet: more
 * products than a page of the list holds, so that it is paged; a product in two
 * categories whose permalink is the first, and one whose permalink is the
 * second, so that the main category is seen to be chosen rather than to come
 * first; a draft; and the rates of tax a shop has besides the standard one. The
 * products are an invented bike shop's and carry the business's name, so that
 * a product of one business turning up in the other is something a person
 * would notice.
 *
 * **The person is the catalogue's role in between**: may view, add, edit and
 * delete products, and may not change what anything costs - the one thing
 * about the catalogue's rights worth clicking through. It is the shop's to
 * seed, because it names the shop's resources and Core may not.
 *
 * Everything goes through the services the administration writes with - see
 * Trilobit\Core\Seed\SeedProvider for why that is the rule. The categories are
 * Core's, so this seeds them with Core's service, beside whatever another
 * module files into categories of its own.
 */
final readonly class ShopSeed implements SeedProvider, SeedsMembers
{
    /**
     * Model, last part of its address, main category, the others, price before
     * tax in hundredths, rate of tax in basis points, published, lead.
     *
     * @var list<array{string, string, string, list<string>, int, int, bool, string}>
     */
    private const array CATALOGUE = [
        ['Ridge 29', 'ridge-29', 'mountain', ['sale'], 2499000, 2100, true, 'A hardtail for long days in the hills.'],
        ['Ridge 27.5', 'ridge-27-5', 'mountain', [], 2399000, 2100, true, 'The same hardtail on smaller wheels.'],
        ['Scree Trail', 'scree-trail', 'mountain', [], 3299000, 2100, true, 'Full suspension for rough ground.'],
        ['Talus Enduro', 'talus-enduro', 'sale', ['mountain'], 3899000, 2100, true, 'Built to go down; last season\'s colour.'],
        ['Moraine Full', 'moraine-full', 'mountain', [], 4599000, 2100, true, 'Long travel for the steepest trails.'],
        ['Cirque Junior', 'cirque-junior', 'mountain', [], 1299000, 2100, false, 'A first mountain bike, still being written about.'],
        ['Basalt Sprint', 'basalt-sprint', 'road', [], 3899000, 2100, true, 'Stiff and light, for racing.'],
        ['Granite Endurance', 'granite-endurance', 'road', [], 2999000, 2100, true, 'Comfortable over a whole day.'],
        ['Gneiss Aero', 'gneiss-aero', 'road', [], 5499000, 2100, true, 'Shaped for speed on the flat.'],
        ['Slate Commuter', 'slate-commuter', 'road', [], 1899000, 2100, true, 'Mudguards and a rack, for every day.'],
        ['Esker All-Road', 'esker-all-road', 'gravel', [], 2799000, 2100, true, 'Tarmac, gravel and whatever is between.'],
        ['Drumlin Adventure', 'drumlin-adventure', 'gravel', [], 3199000, 2100, true, 'Room for wide tyres and bags.'],
        ['Kame Bikepacker', 'kame-bikepacker', 'gravel', [], 3499000, 2100, true, 'For trips of more than one day.'],
        ['Tor Steel', 'tor-steel', 'gravel', [], 2599000, 2100, true, 'A steel frame that will outlast its rider.'],
        ['Chain, 12-speed', 'chain-12-speed', 'parts', [], 89000, 2100, true, 'For twelve sprockets at the back.'],
        ['Inner tube 29', 'inner-tube-29', 'parts', [], 19000, 2100, true, 'A spare for the big wheels.'],
        ['Saddle Comfort', 'saddle-comfort', 'parts', [], 149000, 2100, true, 'Wider, and softer than it looks.'],
        ['Flat pedals', 'flat-pedals', 'parts', [], 129000, 2100, true, 'Grip for ordinary shoes.'],
        ['Lock-on grips', 'lock-on-grips', 'parts', [], 59000, 2100, true, 'They stay where they are put.'],
        ['Floor pump', 'floor-pump', 'parts', [], 99000, 2100, true, 'With a gauge that can be read.'],
        ['Helmet Ridge', 'helmet-ridge', 'parts', [], 199000, 2100, true, 'Light, and vented for climbing.'],
        ['Work stand', 'work-stand', 'parts', [], 349000, 2100, true, 'Holds a bike at the height of the work.'],
        ['Trail map of the hills', 'trail-map', 'parts', [], 29000, 1200, true, 'Every trail in the hills, at the reduced rate.'],
        ['Gift card', 'gift-card', 'parts', [], 100000, 0, true, 'Worth its price in the shop, and taxed when it is spent.'],
    ];

    /** The product that has no SKU, to show that one is optional. */
    private const string WITHOUT_SKU = 'gift-card';

    public function __construct(
        private Products $products,
        private Categories $categories,
    ) {}

    public function members(Tenant $business): array
    {
        return [
            new SeededMember(
                'cataloguer',
                'Colin Coral',
                'catalogue',
                'Catalogue keeper',
                [
                    new Grant(ShopResource::Catalogue, Privilege::View),
                    new Grant(ShopResource::Catalogue, Privilege::Add),
                    new Grant(ShopResource::Catalogue, Privilege::Edit),
                    new Grant(ShopResource::Catalogue, Privilege::Delete),
                ],
                sprintf('keeps the catalogue of %s and may not change what anything in it costs', $business->name()),
            ),
        ];
    }

    public function seed(Tenant $business): array
    {
        $brand = explode(' ', $business->name())[0];

        $bikes = $this->categories->create('Bikes', 'bikes', null);
        $category = [
            'mountain' => $this->categories->create('Mountain bikes', 'mountain', $bikes->ref->id)->ref->id,
            'road' => $this->categories->create('Road bikes', 'road', $bikes->ref->id)->ref->id,
            'gravel' => $this->categories->create('Gravel bikes', 'gravel', $bikes->ref->id)->ref->id,
            'parts' => $this->categories->create('Parts and accessories', 'parts', null)->ref->id,
            'sale' => $this->categories->create('Sale', 'sale', null)->ref->id,
        ];
        $currency = $this->products->prices()->currency;

        foreach (self::CATALOGUE as $number => [$model, $segment, $main, $also, $net, $rate, $published, $lead]) {
            $name = $brand . ' ' . $model;
            $product = $this->products->create(
                $name,
                new Filing($category[$main], array_map(static fn(string $key): string => $category[$key], $also), $segment),
                new Money($net, $currency),
                new VatRate($rate),
            );
            $this->products->describe(
                $product,
                $name,
                $segment === self::WITHOUT_SKU ? null : sprintf('%s-%03d', strtoupper(substr($brand, 0, 3)), $number + 1),
                $lead,
                'Nothing in this description is true. The seed wrote it so that there is something to read.',
            );

            if ($published) {
                $this->products->publish($product);
            }
        }

        return [
            sprintf(
                '%d products in the categories Bikes - with Mountain bikes, Road bikes and Gravel bikes under it - '
                    . 'Parts and accessories, and Sale, so that the list of products runs past its first page',
                count(self::CATALOGUE),
            ),
            sprintf(
                '%1$s Ridge 29 filed under Mountain bikes and Sale with its permalink in Mountain bikes, and %1$s '
                    . 'Talus Enduro under both with its permalink in Sale',
                $brand,
            ),
            sprintf('a draft, %s Cirque Junior', $brand),
            'prices before tax at the standard rate of 21 per cent, a trail map at 12 and a gift card, without an SKU, at none',
        ];
    }
}
