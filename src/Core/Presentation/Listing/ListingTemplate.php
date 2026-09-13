<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Listing;

use Nette\Application\IPresenter;
use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\Template;
use Trilobit\Core\Presentation\Component\Pagination;

/**
 * What a listing is drawn with - templates/listing.latte, and the template of
 * its rows, which declares this class too because it is drawn as part of it.
 *
 * Everything here has been worked out by Listing::render() already: the
 * sentences, the addresses, which of the three states the list is in. The
 * template decides where they go and nothing about what they are.
 */
final class ListingTemplate extends Template
{
    public IPresenter $presenter;

    public Control $control;

    public string $baseUrl = '';

    public string $basePath = '';

    /** @var list<\stdClass> */
    public array $flashes = [];

    /** What every data-testid of the listing starts with. */
    public string $testId = '';

    /** What the table is, for a screen reader arriving at it; see c-table. */
    public string $caption = '';

    /** The file defining the blocks listingHead and listingRow; see Listing::rowsTemplate(). */
    public string $rowsTemplate = '';

    /** @var list<object> the rows of this page, as Listing::rows() made them */
    public array $rows = [];

    /** One of Listing::ROWS, Listing::EMPTY and Listing::NO_MATCH. */
    public string $state = Listing::EMPTY;

    /** How much was found, said out loud when it changes. */
    public string $status = '';

    /** @var list<string> a sentence for each part of the address that could not be used */
    public array $setAside = [];

    public ?Pagination $pagination = null;

    /** The same list without its filters, or '' while nothing filters it. */
    public string $clearUrl = '';

    public string $nothingYet = '';

    public string $nothingMatches = '';
}
