<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Listing;

use Doctrine\ORM\QueryBuilder;
use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use Nette\Application\UI\Template;
use Trilobit\Core\Presentation\Component\Pagination;
use Trilobit\Core\Presentation\Form\FormFactory;

/**
 * A list of rows in the administration, filtered and paged, with its state in
 * the address (.ai/plans/15).
 *
 * **What is here is what every listing does alike; what differs is a
 * descendant's.** A descendant says which filters there are (configure()),
 * what is listed (query(), a Doctrine builder the filters are written into),
 * how a row is drawn (rowsTemplate()) and what the list is called; it may
 * override the protected methods below it where it needs to. The line the
 * plan draws is kept here too: something goes into this class only once two
 * descendants need it, and what one needs is an override in that one.
 *
 * **The two concerns meet only in the query.** The filters add WHERE
 * (Filters), the page adds LIMIT and OFFSET and needs the count (Slice), and
 * both are applied to one builder, so the count the pages are numbered by is
 * taken over the condition the rows are read with.
 *
 * **The state is the address.** The filters and the page are this
 * component's parameters - `?pages-title=ride&pages-page=2` for a listing
 * called `pages` - read in loadState() and written back in saveState(), so
 * every link the listing draws carries them, a link can be sent or bookmarked,
 * and the way back goes back. They are read rather than declared as typed
 * persistent parameters because the filters are the descendant's, and because
 * Nette answers a typed parameter it cannot convert with a 404.
 *
 * **A new filter starts at the first page, by construction.** The filter is a
 * form sent by GET, and the address it is sent to carries none of the
 * listing's state (withoutState()); filterBy() then puts in what the form
 * holds and nothing else. .ai/plans/15 names the trap: kept on the fifth page,
 * a filter that found five rows would look as if it had found none.
 *
 * **An address is read, never obeyed.** What it asks for that the list does
 * not have is set aside with a sentence (Filters::read(), RequestedPage,
 * Slice) rather than refused with an error, which would take the list away
 * from somebody who only followed a link; and rather than dropped without a
 * word, which would leave a list that looks filtered and is not. Nothing the
 * filters do reaches past what the query already reads: the business is
 * Doctrine's tenant filter and the rights are the presenter's gate, both in
 * front of this.
 *
 * **Naja.** The form and the links are sent by Naja where the script runs,
 * and every request that reaches the listing then redraws its three snippets
 * - the filter, the sentence saying how much was found, which is a live
 * region, and the rows. The presenter makes the listing before its template
 * is drawn, or the snippets of a listing nobody has made yet cannot be
 * redrawn. Without the script it is the same form and the same links, loading
 * the page.
 */
abstract class Listing extends Control
{
    /** How many rows a page of a listing holds unless it says otherwise (perPage()). */
    public const int PER_PAGE = 20;

    /** The list has rows to show. */
    public const string ROWS = 'rows';

    /** The list has nothing, and nothing filters it. */
    public const string EMPTY = 'empty';

    /** The list has something, and nothing of it matches the filters. */
    public const string NO_MATCH = 'no match';

    /** @var array<array-key, mixed> what the address carried for this listing, the page included */
    private array $asked = [];

    private ?Filters $filters = null;

    private ?Reading $reading = null;

    public function __construct(private readonly FormFactory $forms)
    {
        // Any request answered with snippets that reaches the page the
        // listing is on redraws it: the one it answers is its own state.
        $this->onAnchor[] = function (): void {
            if ($this->getPresenter()->isAjax()) {
                $this->redrawControl();
            }
        };
    }

    /** The filters this listing may be narrowed by; see Filters. */
    abstract protected function configure(Filters $filters): void;

    /**
     * What is listed, in the order it is listed in, with one root alias the
     * filters are written against - whatever it is called.
     */
    abstract protected function query(): QueryBuilder;

    /**
     * The template defining the blocks `listingHead` - the row of column
     * headers - and `listingRow` - one row, handed as `$row` whatever rows()
     * made of it. It declares {templateType ListingTemplate}.
     */
    abstract protected function rowsTemplate(): string;

    /** What the table is, for somebody arriving at it by keyboard or by a screen reader; see c-table. */
    abstract protected function caption(): string;

    /**
     * What the template draws each row of this page from; the entities
     * themselves unless a descendant makes something of them.
     *
     * @param list<object> $items
     *
     * @return list<object>
     */
    protected function rows(array $items): array
    {
        return $items;
    }

    protected function perPage(): int
    {
        return self::PER_PAGE;
    }

    /** "1 row", "12 rows" - what $count of the listed things are called. */
    protected function counted(int $count): string
    {
        return $count === 1 ? '1 row' : sprintf('%d rows', $count);
    }

    /** What is said while nothing is listed and nothing filters it. */
    protected function nothingYet(): string
    {
        return 'There is nothing here yet.';
    }

    /** What is said while something is listed and none of it matches the filters. */
    protected function nothingMatches(): string
    {
        return 'Nothing matches the filters.';
    }

    /** What every data-testid of the listing starts with. */
    protected function testId(): string
    {
        return (string) $this->getName();
    }

    /** @param array<string, mixed> $params */
    public function loadState(array $params): void
    {
        parent::loadState($params);

        $this->asked = $params;
        $this->reading = null;
    }

    /**
     * The state every link to this page carries: what the filters read, and
     * the page unless it is the first. What was set aside is not written back,
     * so the first link followed is already an address without it.
     *
     * @param array<string, mixed> $params
     */
    public function saveState(array &$params): void
    {
        parent::saveState($params);

        foreach ($this->reading()->values as $name => $value) {
            $params[$name] = $value;
        }

        $page = $this->requestedPage()->number;
        if ($page > 1) {
            $params[RequestedPage::PARAMETER] = $page;
        }
    }

    public function render(): void
    {
        $reading = $this->reading();
        $requested = $this->requestedPage();

        $query = $this->query();
        $this->filters()->applyTo($query, $reading);
        $slice = Slice::of($query, $requested->number, $this->perPage());

        $state = match (true) {
            $slice->total > 0 => self::ROWS,
            $reading->isFiltered() => self::NO_MATCH,
            default => self::EMPTY,
        };

        $template = $this->listingTemplate();
        $template->testId = $this->testId();
        $template->caption = $this->caption();
        $template->rowsTemplate = $this->rowsTemplate();
        $template->rows = $this->rows($slice->items);
        $template->state = $state;
        $template->status = $this->status($state, $slice, $reading);
        $template->setAside = array_values(array_filter(
            [...$reading->setAside, $requested->setAside, $slice->setAside],
            static fn(?string $sentence): bool => $sentence !== null,
        ));
        $template->pagination = Pagination::fromPaginator(
            $slice->paginator,
            fn(int $page): string => $this->address($reading->values, $page),
        );
        $template->clearUrl = $reading->isFiltered() ? $this->address([], 1) : '';
        $template->nothingYet = $this->nothingYet();
        $template->nothingMatches = $this->nothingMatches();

        $template->setFile(__DIR__ . '/templates/listing.latte');
        $template->render();
    }

    /**
     * The framework's getTemplate() is final, so the template class is chosen
     * here and checked where it is used.
     */
    protected function createTemplate(?string $class = null): Template
    {
        return parent::createTemplate($class ?? ListingTemplate::class);
    }

    /**
     * The filters as a form in the inline arrangement, sent by GET.
     *
     * It is sent to the listing's address without the listing's state, so that
     * what arrives is what the form holds and nothing else - the page least of
     * all. Naja sends it where the script runs; without it, it is an ordinary
     * form, and filterBy() answers it with a redirect to the address it asked
     * for.
     */
    protected function createComponentFilter(): Form
    {
        $form = $this->forms->createInline();
        $form->setMethod(Form::Get);
        $form->getElementPrototype()->addClass('ajax');
        $form->setAction($this->address([], 1));

        foreach ($this->filters()->all() as $filter) {
            if ($filter->isChoice()) {
                $form->addSelect($filter->name, $filter->label, $filter->choices)
                    ->setPrompt($filter->prompt);
            } else {
                $form->addText($filter->name, $filter->label)
                    ->setMaxLength(Filter::MAX_LENGTH);
            }
        }

        $form->setDefaults($this->reading()->values);
        $form->addSubmit('show', 'Show');
        $form->onSuccess[] = $this->filterBy(...);

        return $form;
    }

    /**
     * The filters the form holds become the listing's state, and the page is
     * the first because nothing else says otherwise.
     *
     * Answered with a redirect without the script, so the address in the bar
     * is the listing's own rather than the form's; with it, with the snippets
     * and the same address for Naja to put into the history (postGet), so
     * that the way back leads to the list as it was rather than to a form
     * being sent.
     */
    private function filterBy(Form $form): void
    {
        $asked = [];
        foreach ($form->getValues('array') as $name => $value) {
            if (is_string($value) || is_int($value)) {
                $asked[$name] = (string) $value;
            }
        }

        $this->asked = $asked;
        $this->reading = null;

        $address = $this->address($this->reading()->values, 1);
        $presenter = $this->getPresenter();
        if (!$presenter->isAjax()) {
            $presenter->redirectUrl($address);
        }

        $this->redrawControl();
        $presenter->payload->postGet = true;
        $presenter->payload->url = $address;
    }

    /**
     * The listing's address with $values as its filters and $page as its page,
     * every parameter of the listing stated.
     *
     * Stated rather than left to saveState(), because by the time a signal
     * runs the presenter has already taken the state every link carries: a
     * link made after the form was sent would still carry the filter it
     * replaced, and so would every row of pages drawn in the same answer.
     * The first page is the address without a page, so the page a list opens
     * at and the page its row of pages leads back to are one address.
     *
     * @param array<string, string> $values
     */
    private function address(array $values, int $page): string
    {
        $parameters = $this->withoutState();
        foreach ($values as $name => $value) {
            $parameters[$name] = $value;
        }

        if ($page > 1) {
            $parameters[RequestedPage::PARAMETER] = $page;
        }

        return $this->link('this', $parameters);
    }

    /**
     * What the listing says it found, said out loud whenever it changes.
     */
    private function status(string $state, Slice $slice, Reading $reading): string
    {
        if ($state === self::EMPTY) {
            return $this->nothingYet();
        }

        if ($state === self::NO_MATCH) {
            return $this->nothingMatches();
        }

        $pages = (int) $slice->paginator->getPageCount();

        return sprintf(
            '%s%s%s.',
            $this->counted($slice->total),
            $reading->isFiltered() ? ' matching the filters' : '',
            $pages > 1 ? sprintf(', page %d of %d', $slice->paginator->getPage(), $pages) : '',
        );
    }

    /**
     * Every parameter of the listing, taken out of an address.
     *
     * @return array<string, int|string|null>
     */
    private function withoutState(): array
    {
        $cleared = [RequestedPage::PARAMETER => null];
        foreach (array_keys($this->filters()->all()) as $name) {
            $cleared[$name] = null;
        }

        return $cleared;
    }

    private function reading(): Reading
    {
        return $this->reading ??= $this->filters()->read(
            array_diff_key($this->asked, [RequestedPage::PARAMETER => true]),
        );
    }

    private function requestedPage(): RequestedPage
    {
        return RequestedPage::read($this->asked[RequestedPage::PARAMETER] ?? null);
    }

    private function filters(): Filters
    {
        return $this->filters ??= $this->configured();
    }

    /** The descendant's filters, asked for once. */
    private function configured(): Filters
    {
        $filters = new Filters();
        $this->configure($filters);

        return $filters;
    }

    private function listingTemplate(): ListingTemplate
    {
        $template = $this->getTemplate();
        if (!$template instanceof ListingTemplate) {
            throw new \LogicException(sprintf('The template of %s has to be a %s.', static::class, ListingTemplate::class));
        }

        return $template;
    }
}
