<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Content;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Content\PathRefused;
use Trilobit\Core\Content\PathRegistry;
use Trilobit\Core\Content\PublicPath;
use Trilobit\Core\Contract\Content\ContentRef;
use Trilobit\Core\Routing\AdminRoutes;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * The register of public addresses against the database it is stored in.
 *
 * Everything here is a claim about the moment somebody saves, because that is
 * where every decision about the address space is made. A collision settled at
 * read time would be settled by the order the modules happen to be registered
 * in; an address under a reserved beginning saved without complaint would be
 * a page that is never reachable again and that nobody is told about.
 *
 * The content is invented and belongs to no module: what is under test is the
 * register, and the modules that will write into it do not exist yet.
 */
#[CoversNothing]
final class PathRegistryTest extends TestCase
{
    private const string PAGE = 'demo.page';

    private const string CATEGORY = 'demo.category';

    private const string PRODUCT = 'demo.product';

    private string $schema = '';

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            Database::drop($this->schema);
        }
    }

    public function testAnAddressAnswersForWhoeverClaimedIt(): void
    {
        $registry = $this->emptyRegistry();
        $registry->register(new ContentRef(self::PAGE, '1'), 'about', 'About us');

        $address = $registry->find('about');

        self::assertNotNull($address);
        self::assertSame('about', $address->path);
        self::assertSame(self::PAGE, $address->ref->type);
        self::assertSame('1', $address->ref->id);
        self::assertSame('About us', $address->label);
        self::assertFalse($address->hasMoved());
        self::assertTrue($address->isCanonical());
    }

    public function testAnAddressNobodyClaimedIsNotFound(): void
    {
        self::assertNull($this->emptyRegistry()->find('about'));
    }

    /**
     * Decision R2: whoever writes second is told so, rather than whoever reads
     * first winning.
     */
    public function testClaimingAnAddressSomebodyElseHoldsFailsWhileSaving(): void
    {
        $registry = $this->emptyRegistry();
        $registry->register(new ContentRef(self::PAGE, '1'), 'about', 'About us');

        $this->expectException(PathRefused::class);
        $this->expectExceptionMessage("'about' is already the address of something else.");

        $registry->register(new ContentRef(self::PRODUCT, '9'), 'about', 'A product');
    }

    /**
     * Decision R6, and the reason it is enforced here rather than by the order
     * of the routes: ordering would let this save and then never be reachable.
     */
    public function testAnAddressUnderAReservedBeginningFailsWhileSaving(): void
    {
        $registry = $this->emptyRegistry();

        $this->expectException(PathRefused::class);
        $this->expectExceptionMessage(sprintf("cannot start with '%s'", AdminRoutes::PATH));

        $registry->register(new ContentRef(self::PAGE, '1'), AdminRoutes::PATH, 'A page called admin');
    }

    public function testAReservedBeginningIsRefusedEvenDeepInsideAnAddress(): void
    {
        $registry = $this->emptyRegistry();

        $this->expectException(PathRefused::class);

        $registry->register(new ContentRef(self::PAGE, '1'), AdminRoutes::PATH . '/reports', 'Reports');
    }

    public function testAnAddressInAnyOtherSpellingFailsWhileSaving(): void
    {
        $registry = $this->emptyRegistry();

        $this->expectException(PathRefused::class);
        $this->expectExceptionMessage('is not the shape an address is stored in');

        $registry->register(new ContentRef(self::PAGE, '1'), 'About/Us/', 'About us');
    }

    /**
     * Decision R7: the limit is the length of an address, which is what the
     * unique index can carry, and not a depth somebody made up.
     */
    public function testAnAddressLongerThanTheIndexCanCarryFailsWhileSaving(): void
    {
        $registry = $this->emptyRegistry();

        $this->expectException(PathRefused::class);
        $this->expectExceptionMessage('an address may be at most');

        $registry->register(new ContentRef(self::PAGE, '1'), str_repeat('a', PublicPath::MAX_LENGTH + 1), 'Long');
    }

    /** Decision R7: depth costs nothing, and each level is an address of its own. */
    public function testCategoriesThreeDeepEachHaveTheirOwnAddress(): void
    {
        $registry = $this->threeLevelCatalogue();

        foreach (['bikes', 'bikes/mountain', 'bikes/mountain/full-suspension'] as $path) {
            $address = $registry->find($path);
            self::assertNotNull($address, $path . ' has no address');
            self::assertSame(self::CATEGORY, $address->ref->type);
        }

        self::assertSame('bikes/mountain', $registry->find('bikes/mountain/full-suspension')?->parentPath);
        self::assertSame('bikes', $registry->find('bikes/mountain')?->parentPath);
        self::assertNull($registry->find('bikes')?->parentPath);
    }

    /**
     * Decision R11: a category is a row like any other, so a product cannot
     * take an address a category already holds - the same unique index, no
     * special case in the code.
     */
    public function testAProductCannotTakeTheAddressOfACategory(): void
    {
        $registry = $this->threeLevelCatalogue();

        $this->expectException(PathRefused::class);

        $registry->register(new ContentRef(self::PRODUCT, '1'), 'bikes/mountain', 'A product', 'bikes');
    }

    /** Decision R12: as many addresses as there are categories, one of them the permalink. */
    public function testAProductInTwoCategoriesHoldsTwoAddressesAndOnePermalink(): void
    {
        $registry = $this->productInTwoCategories();
        $product = new ContentRef(self::PRODUCT, '1');

        $addresses = $registry->addressesOf($product);

        self::assertSame(
            ['bikes/mountain/mountain-bike-x', 'sale/mountain-bike-x'],
            array_map(static fn(Address $address): string => $address->path, $addresses),
        );
        self::assertTrue($addresses[0]->isCanonical());
        self::assertFalse($addresses[1]->isCanonical());
        self::assertSame('bikes/mountain/mountain-bike-x', $registry->find('sale/mountain-bike-x')?->canonicalPath);
    }

    /**
     * Decision R12: the permalink is a decision somebody makes, so nothing
     * that merely files a product somewhere else may move it.
     */
    public function testFilingAProductOutOfACategoryLeavesThePermalinkWhereItIs(): void
    {
        $registry = $this->productInTwoCategories();
        $product = new ContentRef(self::PRODUCT, '1');

        $registry->register($product, 'clearance/mountain-bike-x', 'Mountain bike X', 'clearance');
        $registry->forget('sale/mountain-bike-x');

        self::assertSame('bikes/mountain/mountain-bike-x', $registry->find('clearance/mountain-bike-x')?->canonicalPath);
    }

    public function testThePermalinkMovesOnlyWhenSomebodySaysSo(): void
    {
        $registry = $this->productInTwoCategories();
        $product = new ContentRef(self::PRODUCT, '1');

        $registry->makeCanonical($product, 'sale/mountain-bike-x');

        self::assertSame('sale/mountain-bike-x', $registry->find('bikes/mountain/mountain-bike-x')?->canonicalPath);
        $permalink = $registry->addressesOf($product)[0];
        self::assertTrue($permalink->isCanonical());
        self::assertSame('sale/mountain-bike-x', $permalink->path);
    }

    /**
     * The permalink is held on to while another address of the same content is
     * registered, so that it is never moved by whatever happens to be removed
     * next.
     */
    public function testThePermalinkCannotBeGivenUpWhileOtherAddressesRemain(): void
    {
        $registry = $this->productInTwoCategories();

        $this->expectException(PathRefused::class);
        $this->expectExceptionMessage('is the canonical address of its content');

        $registry->forget('bikes/mountain/mountain-bike-x');
    }

    /** Decision R4: an address that moves keeps answering, permanently redirected. */
    public function testARenamedAddressIsLeftBehindAsAPermanentRedirect(): void
    {
        $registry = $this->threeLevelCatalogue();

        $registry->rename('bikes/mountain', 'bikes/off-road');

        self::assertSame('bikes/off-road', $registry->find('bikes/mountain')?->movedTo);

        $moved = $registry->find('bikes/off-road');
        self::assertNotNull($moved);
        self::assertFalse($moved->hasMoved());
    }

    /** Decision R4: and so does everything that was filed under it. */
    public function testEveryDescendantOfARenamedAddressIsRedirectedToo(): void
    {
        $registry = $this->threeLevelCatalogue();
        $registry->register(new ContentRef(self::PRODUCT, '1'), 'bikes/mountain/mountain-bike-x', 'Mountain bike X', 'bikes/mountain');

        $registry->rename('bikes/mountain', 'bikes/off-road');

        self::assertSame(
            'bikes/off-road/full-suspension',
            $registry->find('bikes/mountain/full-suspension')?->movedTo,
        );
        self::assertSame(
            'bikes/off-road/mountain-bike-x',
            $registry->find('bikes/mountain/mountain-bike-x')?->movedTo,
        );
        self::assertSame('bikes/off-road', $registry->find('bikes/off-road/full-suspension')?->parentPath);
    }

    /** A rename twice over leads to the newest address rather than to the middle one. */
    public function testAnAddressRenamedTwiceLeadsToTheNewestOne(): void
    {
        $registry = $this->threeLevelCatalogue();

        $registry->rename('bikes/mountain', 'bikes/off-road');
        $registry->rename('bikes/off-road', 'bikes/trail');

        self::assertSame('bikes/trail', $registry->find('bikes/mountain')?->movedTo);
        self::assertSame('bikes/trail', $registry->find('bikes/off-road')?->movedTo);
    }

    public function testRenamingOntoAnAddressSomebodyElseHoldsFailsWhileSaving(): void
    {
        $registry = $this->threeLevelCatalogue();
        $registry->register(new ContentRef(self::CATEGORY, '9'), 'bikes/road', 'Road bikes', 'bikes');

        $this->expectException(PathRefused::class);
        $this->expectExceptionMessage("'bikes/road' is already the address of something else.");

        $registry->rename('bikes/mountain', 'bikes/road');
    }

    public function testRenamingUnderAReservedBeginningFailsWhileSaving(): void
    {
        $registry = $this->threeLevelCatalogue();

        $this->expectException(PathRefused::class);
        $this->expectExceptionMessage(sprintf("cannot start with '%s'", AdminRoutes::PATH));

        $registry->rename('bikes', AdminRoutes::PATH);
    }

    public function testTheLastPartIsJoinedUnderTheAddressAboveIt(): void
    {
        $registry = $this->emptyRegistry();

        self::assertSame('bikes/mountain', $registry->addressUnder('bikes', 'mountain'));
        self::assertSame('bikes', $registry->addressUnder(null, 'bikes'));
    }

    /**
     * Decision C2: only the last part is written, and it is one part. A slash
     * would be a deeper address typed by hand - a row whose parents do not
     * exist - and a dot is the module separator of Nette's routing, with
     * .html as its commonest shape.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function refusedLastParts(): iterable
    {
        yield 'a slash' => ['guides/first-ride', 'more than one part'];
        yield 'an extension' => ['first-ride.html', 'a dot'];
        yield 'a dot' => ['first.ride', 'a dot'];
        yield 'upper case' => ['First-Ride', "'first-ride' says the same thing"];
        yield 'nothing' => ['', 'cannot be empty'];
    }

    #[DataProvider('refusedLastParts')]
    public function testALastPartThatIsNotOneSegmentIsRefused(string $written, string $sentence): void
    {
        $registry = $this->emptyRegistry();

        $this->expectException(PathRefused::class);
        $this->expectExceptionMessage($sentence);

        $registry->addressUnder(null, $written);
    }

    /**
     * A suggestion is a title put into the stored shape and then run through
     * the same refusals saving runs through - which is why it is the register
     * that makes it, and not the browser.
     */
    public function testASuggestionIsTheTitleInTheShapeAnAddressIsStoredIn(): void
    {
        self::assertSame('mountain-bikes-co', $this->emptyRegistry()->suggest('Mountain bikes & co.'));
    }

    /** Decision R2, asked before saving rather than after: a taken address is not suggested. */
    public function testASuggestionSkipsAnAddressAlreadyTaken(): void
    {
        $registry = $this->emptyRegistry();
        $registry->register(new ContentRef(self::PAGE, '1'), 'about', 'About');
        $registry->register(new ContentRef(self::PAGE, '2'), 'about-2', 'About again');

        self::assertSame('about-3', $registry->suggest('About'));
    }

    /** Decision R6: a title that is a reserved beginning is not suggested at the root of the site. */
    public function testASuggestionAtTheRootSkipsAReservedBeginning(): void
    {
        $registry = $this->emptyRegistry();

        $suggested = $registry->suggest(ucfirst(AdminRoutes::PATH));

        self::assertSame(AdminRoutes::PATH . '-2', $suggested);
        $registry->register(new ContentRef(self::PAGE, '1'), $suggested, 'Admin');
    }

    /** Taken means taken at the address it would really have, under whatever it is filed under. */
    public function testASuggestionUnderAParentOnlyHasToBeFreeThere(): void
    {
        $registry = $this->threeLevelCatalogue();

        self::assertSame('mountain-2', $registry->suggest('Mountain', 'bikes'));
        self::assertSame('road', $registry->suggest('Road', 'bikes'));
    }

    /** An address is not taken from whoever already holds it, so re-suggesting for a saved page leaves it where it is. */
    public function testASuggestionLeavesContentItsOwnAddress(): void
    {
        $registry = $this->emptyRegistry();
        $page = new ContentRef(self::PAGE, '1');
        $registry->register($page, 'about', 'About');

        self::assertSame('about', $registry->suggest('About', null, $page));
    }

    /** Decision R7: the whole address has to fit the index, so a long title is cut to what is left under its parent. */
    public function testASuggestionFitsTheLengthAnAddressMayHave(): void
    {
        $registry = $this->threeLevelCatalogue();

        $suggested = $registry->suggest(str_repeat('word ', 80), 'bikes/mountain');

        self::assertLessThanOrEqual(PublicPath::MAX_LENGTH, strlen('bikes/mountain/' . $suggested));
        self::assertTrue(PublicPath::isSegment($suggested), $suggested . ' is not one segment');
        $registry->register(new ContentRef(self::PAGE, '1'), 'bikes/mountain/' . $suggested, 'Long', 'bikes/mountain');
    }

    public function testATitleWithNothingToKeepSuggestsNothing(): void
    {
        self::assertSame('', $this->emptyRegistry()->suggest('!!!'));
    }

    /**
     * Forgetting an address used to let the database's cascade take whatever
     * was filed under it, behind Doctrine's back. It is refused instead, with
     * a sentence, so that deleting a category never deletes a page.
     */
    public function testAnAddressWithSomethingFiledUnderItIsNotForgotten(): void
    {
        $registry = $this->threeLevelCatalogue();

        $this->expectException(PathRefused::class);
        $this->expectExceptionMessage('still has');

        $registry->forget('bikes/mountain');
    }

    public function testAnAddressCannotBeMovedIntoItsOwnBranch(): void
    {
        $registry = $this->threeLevelCatalogue();

        $this->expectException(PathRefused::class);
        $this->expectExceptionMessage('inside it');

        $registry->rename('bikes', 'bikes/mountain/bikes');
    }

    /** The same refusal register() makes, now made on a move too: a row whose parent does not answer is not a tree. */
    public function testAnAddressCannotBeMovedUnderOneNobodyHolds(): void
    {
        $registry = $this->threeLevelCatalogue();

        $this->expectException(PathRefused::class);
        $this->expectExceptionMessage('no address answers there');

        $registry->rename('bikes/mountain', 'cars/mountain');
    }

    private function threeLevelCatalogue(): PathRegistry
    {
        $registry = $this->emptyRegistry();
        $registry->register(new ContentRef(self::CATEGORY, '1'), 'bikes', 'Bikes');
        $registry->register(new ContentRef(self::CATEGORY, '2'), 'bikes/mountain', 'Mountain bikes', 'bikes');
        $registry->register(new ContentRef(self::CATEGORY, '3'), 'bikes/mountain/full-suspension', 'Full suspension', 'bikes/mountain');

        return $registry;
    }

    private function productInTwoCategories(): PathRegistry
    {
        $registry = $this->threeLevelCatalogue();
        $registry->register(new ContentRef(self::CATEGORY, '4'), 'sale', 'Sale');
        $registry->register(new ContentRef(self::CATEGORY, '5'), 'clearance', 'Clearance');

        $product = new ContentRef(self::PRODUCT, '1');
        $registry->register($product, 'bikes/mountain/mountain-bike-x', 'Mountain bike X', 'bikes/mountain');
        $registry->register($product, 'sale/mountain-bike-x', 'Mountain bike X', 'sale');

        return $registry;
    }

    private function emptyRegistry(): PathRegistry
    {
        $this->schema = Database::schemaFor(self::class);
        $container = Boot::coreAlone();
        Migrations::run($container);
        Tenants::enter($container);

        return $container->getByType(PathRegistry::class);
    }
}
