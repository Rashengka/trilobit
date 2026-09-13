<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Cms;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nette\DI\Container;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Cms\Application\Page\Pages;
use Trilobit\Cms\Domain\Page\Page;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Presentation\Listing\Comparison;
use Trilobit\Core\Presentation\Listing\Filters;
use Trilobit\Core\Presentation\Listing\Slice;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * The filters and the pages of a listing, over a real query and a real
 * database - the half of .ai/plans/15 that only Doctrine can answer.
 *
 * What is claimed here is what cannot be seen on the page: that the builder a
 * listing is handed is narrowed at the alias it really has, that what was
 * typed reaches the database as a bound value and never as part of the query,
 * that the count the pages are numbered by is taken over the same condition as
 * the rows, and that none of it reaches past the tenant.
 *
 * The pages are the module's own, written through the service the
 * administration writes with, because a listing over rows put in past it
 * would show a state nobody can make.
 */
#[CoversNothing]
final class PageListingQueryTest extends TestCase
{
    private string $schema = '';

    private ?Container $container = null;

    private ?Tenant $ammonite = null;

    protected function tearDown(): void
    {
        $this->container = null;
        $this->ammonite = null;

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    /**
     * The alias is read from the builder rather than agreed on (.ai/plans/15,
     * decision 1): two builders with two aliases, one set of filters.
     */
    public function testTheFilterIsWrittenAgainstTheAliasTheBuilderHas(): void
    {
        $this->pagesTitled('First ride', 'Second ride', 'A walk');

        foreach (['p', 'whatever'] as $alias) {
            $query = $this->builder($alias);
            $this->filters()->applyTo($query, $this->filters()->read(['title' => 'ride']));

            self::assertStringContainsString($alias . '.title LIKE :', $query->getDQL());
            self::assertSame(['First ride', 'Second ride'], $this->titles($query), 'with the alias ' . $alias);
        }
    }

    /** What was typed is a parameter of the query and never a part of its text. */
    public function testWhatWasTypedIsBoundAndNeverWrittenIntoTheQuery(): void
    {
        $this->pagesTitled('First ride', 'A walk');
        $typed = "x' OR '1'='1";

        $query = $this->builder();
        $this->filters()->applyTo($query, $this->filters()->read(['title' => $typed]));

        self::assertStringNotContainsString($typed, $query->getDQL());
        self::assertSame([], $this->titles($query), 'the typed quote reached the query as SQL');
    }

    /** A per cent sign typed into the field is looked for, not read as "anything". */
    public function testWhatLikeReadsIsLookedForAsItWasTyped(): void
    {
        $this->pagesTitled('Half price', '50% off', '5 bells');

        $query = $this->builder();
        $this->filters()->applyTo($query, $this->filters()->read(['title' => '%']));
        self::assertSame(['50% off'], $this->titles($query));

        $query = $this->builder();
        $this->filters()->applyTo($query, $this->filters()->read(['title' => '5_']));
        self::assertSame([], $this->titles($query), 'an underscore matched any one character');
    }

    public function testAChoiceNarrowsByItsField(): void
    {
        $this->pagesTitled('First ride', 'Second ride');
        $published = $this->pages()->all()[0];
        $this->pages()->publish($published);

        $query = $this->builder();
        $this->filters()->applyTo($query, $this->filters()->read(['status' => 'published']));

        self::assertSame([$published->title()], $this->titles($query));
    }

    /** A name nobody configured never reaches the query at all, not even as a condition that fails. */
    public function testWhatIsSetAsideLeavesTheQueryAsItWas(): void
    {
        $this->pagesTitled('First ride', 'A walk');

        $query = $this->builder();
        $before = $query->getDQL();
        $this->filters()->applyTo($query, $this->filters()->read(['colour' => 'red', 'status' => 'bogus']));

        self::assertSame($before, $query->getDQL());
        self::assertCount(0, $query->getParameters());
    }

    /**
     * A filter naming a field the entity does not have is a mistake in the
     * source, and it is refused when the listing is drawn - whether or not
     * anybody filtered by it - rather than on the day somebody first does.
     */
    public function testAFieldTheEntityDoesNotHaveIsRefusedEvenUnused(): void
    {
        $filters = new Filters()->text('colour', 'Colour', Comparison::Equals);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('colour');

        $filters->applyTo($this->builder(), $filters->read([]));
    }

    /** Two roots are two aliases, and which one a filter means is not a guess to make. */
    public function testABuilderWithTwoRootsIsRefused(): void
    {
        $query = $this->builder()->addSelect('other')->from(Page::class, 'other');

        $this->expectException(\LogicException::class);

        $this->filters()->applyTo($query, $this->filters()->read(['title' => 'ride']));
    }

    /**
     * The count the pages are numbered by is taken over the same condition as
     * the rows (.ai/plans/15, the second trap): three pages of two, filtered.
     */
    public function testTheCountIsTakenOverTheSameConditionAsTheRows(): void
    {
        $this->pagesTitled('Ride 1', 'Ride 2', 'Ride 3', 'Ride 4', 'Ride 5', 'Walk 1', 'Walk 2');

        $query = $this->builder();
        $this->filters()->applyTo($query, $this->filters()->read(['title' => 'ride']));
        $slice = Slice::of($query, 3, 2);

        self::assertSame(5, $slice->total);
        self::assertSame(3, $slice->paginator->getPageCount());
        self::assertSame(3, $slice->paginator->getPage());
        self::assertSame(['Ride 5'], array_map(static fn(object $page): string => $page instanceof Page ? $page->title() : '', $slice->items));
        self::assertNull($slice->setAside);
    }

    /**
     * A page past the last is the last, and it says so - drawn as it was
     * asked, it would be an empty table under a filter that found five.
     */
    public function testAPagePastTheLastIsTheLastAndSaysSo(): void
    {
        $this->pagesTitled('Ride 1', 'Ride 2', 'Ride 3');

        $slice = Slice::of($this->builder(), 99, 2);

        self::assertSame(2, $slice->paginator->getPage());
        self::assertCount(1, $slice->items);
        self::assertNotNull($slice->setAside);
        self::assertStringContainsString('99', $slice->setAside);
    }

    /**
     * Rows with the same title are still paged through once each: the order is
     * made total by the identifier, whatever the listing sorted by. Without it
     * the database may hand the tied rows out in a different order for each
     * page, and one is shown twice while another is never shown.
     */
    public function testRowsThatTieInTheOrderArePagedThroughOnceEach(): void
    {
        $this->pagesTitled('Same', 'Same', 'Same', 'Same', 'Same');

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            foreach (Slice::of($this->builder(), $page, 2)->items as $item) {
                self::assertInstanceOf(Page::class, $item);
                $seen[] = $item->id();
            }
        }

        self::assertCount(5, array_unique($seen));
    }

    /**
     * The filter narrows what the tenant filter already narrowed, and never
     * the other way: the same filter in two businesses, each reading its own
     * rows and counting its own rows.
     */
    public function testTheFilterReadsOnlyTheRowsOfTheBusinessItIsIn(): void
    {
        $container = $this->container();
        $this->pagesTitled('Ride in the hills', 'Ride by the sea');
        $ammonite = $this->ammonite ?? throw new \LogicException('The container enters a business as it is made.');
        $belemnite = Tenants::create($container, 'Belemnite Books');
        Tenants::switchTo($container, $belemnite);
        $this->pagesTitled('Ride through the library');

        foreach ([[$belemnite, ['Ride through the library']], [$ammonite, ['Ride by the sea', 'Ride in the hills']]] as [$business, $expected]) {
            Tenants::switchTo($container, $business);
            $query = $this->builder();
            $this->filters()->applyTo($query, $this->filters()->read(['title' => 'ride']));
            $slice = Slice::of($query, 1, 20);

            self::assertSame($expected, $this->titlesOf($slice->items), $business->name());
            self::assertSame(count($expected), $slice->total, 'counted past the tenant in ' . $business->name());
        }
    }

    private function filters(): Filters
    {
        return new Filters()
            ->text('title', 'Title', Comparison::Contains)
            ->choice('status', 'Status', ['draft' => 'Draft', 'published' => 'Published'], Comparison::Equals);
    }

    private function builder(string $alias = 'page'): QueryBuilder
    {
        return $this->container()->getByType(EntityManagerInterface::class)
            ->createQueryBuilder()
            ->select($alias)
            ->from(Page::class, $alias)
            ->orderBy($alias . '.title', 'ASC');
    }

    /** @return list<string> */
    private function titles(QueryBuilder $query): array
    {
        /** @var list<Page> $pages */
        $pages = $query->getQuery()->getResult();

        return $this->titlesOf($pages);
    }

    /**
     * @param list<object> $pages
     *
     * @return list<string>
     */
    private function titlesOf(array $pages): array
    {
        return array_map(static fn(object $page): string => $page instanceof Page ? $page->title() : '', $pages);
    }

    private function pagesTitled(string ...$titles): void
    {
        foreach ($titles as $index => $title) {
            $this->pages()->create($title, 'page-' . bin2hex(random_bytes(4)) . '-' . $index);
        }
    }

    private function pages(): Pages
    {
        return $this->container()->getByType(Pages::class);
    }

    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::container(ModuleList::of(
            ['cms' => true, 'crm' => false, 'shop' => false],
            Bootstrap::rootDirectory(),
        ));
        Migrations::run($container);
        $this->ammonite = Tenants::enter($container, 'Ammonite Bikes');

        return $this->container = $container;
    }
}
