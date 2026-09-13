<?php

declare(strict_types=1);

namespace Trilobit\Shop\Security;

use Trilobit\Core\Security\ResourceName;

/**
 * What a permission question about the shop is about.
 *
 * The shop's own resources, in the shop's own enum: Core may not name a
 * module, so it cannot hold them, and brings them into the build only through
 * Trilobit\Shop\Security\ShopResources. What may be asked of each is said in
 * permissions.neon beside this file. A build without the shop has none of
 * them; a role naming one keeps the piece and it holds again the day the shop
 * is switched back on.
 *
 * They fall under Core's administration by their names, and the shape of the
 * tree is decided (2026-09-12, H1, H4 and H6):
 *
 * - **the section is the root** and the whole of it may be granted, as the
 *   root of a module's section is meant to be;
 * - **the price is a sibling of the catalogue, not a part of it.** It is
 *   sensitive - what a product costs and the rate of tax on it - so somebody
 *   may be trusted to write about a product without being trusted to change
 *   what it costs, and what is withheld has to be a sibling of what is given
 *   rather than something above or below it.
 */
enum ShopResource: string implements ResourceName
{
    /** The shop's section of the administration, and what everything else of the shop falls under. */
    case Shop = 'app.administration.shop';

    /** The products, their pictures and the categories they are filed in. */
    case Catalogue = 'app.administration.shop.catalogue';

    /** What a product costs before tax, and the rate of tax on it. */
    case Price = 'app.administration.shop.price';
}
