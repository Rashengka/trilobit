<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Content;

use Nette\DI\Container;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Content\Categories;
use Trilobit\Core\Content\PathRefused;
use Trilobit\Core\Content\PathRegistry;
use Trilobit\Core\Content\Placement;
use Trilobit\Core\Contract\Content\ContentRef;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * Categories against the register they are rows of.
 *
 * A category is not a table of its own: it is an address in the register that
 * other addresses are filed under, and nothing more. So every claim here is a
 * claim about the register - what a category makes of the addresses beneath
 * it when it is renamed, and what it refuses to take with it when it is
 * deleted.
 *
 * The content filed under the categories is invented and belongs to no
 * module, for the same reason it does in PathRegistryTest.
 */
#[CoversNothing]
final class CategoriesTest extends TestCase
{
    private const string PAGE = 'demo.page';

    private string $schema = '';

    private ?Container $container = null;

    protected function tearDown(): void
    {
        $this->container = null;

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testACategoryIsAnAddressInTheRegisterAndNothingElse(): void
    {
        $categories = $this->categories();

        $guides = $categories->create('Guides', 'guides', null);

        $address = $this->registry()->find('guides');
        self::assertNotNull($address);
        self::assertSame(Categories::TYPE, $address->ref->type);
        self::assertSame($guides->ref->id, $address->ref->id);
        self::assertSame('Guides', $address->label);
        self::assertNull($address->parentPath);

        self::assertSame(['guides'], array_map(static fn(Address $category): string => $category->path, $categories->all()));
    }

    public function testACategoryUnderAnotherTakesItsAddressAsTheBeginningOfItsOwn(): void
    {
        $categories = $this->categories();
        $guides = $categories->create('Guides', 'guides', null);

        $categories->create('Winter', 'winter', $guides->ref->id);

        self::assertSame('guides', $this->registry()->find('guides/winter')?->parentPath);
    }

    /** Decision R4, the reason the plan says it must not be done later: a rename moves every address beneath and leaves each old one answering. */
    public function testRenamingACategoryRedirectsEverythingFiledUnderIt(): void
    {
        $categories = $this->categories();
        $guides = $categories->create('Guides', 'guides', null);
        $winter = $categories->create('Winter', 'winter', $guides->ref->id);
        $this->registry()->register(new ContentRef(self::PAGE, '1'), 'guides/first-ride', 'First ride', 'guides');
        $this->registry()->register(new ContentRef(self::PAGE, '2'), 'guides/winter/ice', 'Ice', 'guides/winter');

        $categories->revise($guides->ref->id, 'Handbook', 'handbook', null);

        self::assertSame('handbook/first-ride', $this->registry()->find('guides/first-ride')?->movedTo);
        self::assertSame('handbook/winter/ice', $this->registry()->find('guides/winter/ice')?->movedTo);
        self::assertSame('handbook', $this->registry()->find('guides')?->movedTo);
        self::assertSame('Handbook', $this->registry()->find('handbook')?->label);
        self::assertSame('handbook/winter', $categories->find($winter->ref->id)?->path);
    }

    public function testACategoryCanBeMovedUnderAnother(): void
    {
        $categories = $this->categories();
        $guides = $categories->create('Guides', 'guides', null);
        $winter = $categories->create('Winter', 'winter', null);

        $categories->revise($winter->ref->id, 'Winter', 'winter', $guides->ref->id);

        self::assertSame('guides/winter', $this->registry()->find('winter')?->movedTo);
        self::assertSame('guides', $this->registry()->find('guides/winter')?->parentPath);
    }

    public function testACategoryCannotBeMovedIntoItself(): void
    {
        $categories = $this->categories();
        $guides = $categories->create('Guides', 'guides', null);
        $winter = $categories->create('Winter', 'winter', $guides->ref->id);

        $this->expectException(PathRefused::class);
        $this->expectExceptionMessage('inside it');

        $categories->revise($guides->ref->id, 'Guides', 'guides', $winter->ref->id);
    }

    /** Deleting a category that holds something would take it along, and plan 16 decides where it should go instead. */
    public function testACategoryWithSomethingFiledUnderItIsNotDeleted(): void
    {
        $categories = $this->categories();
        $guides = $categories->create('Guides', 'guides', null);
        $this->registry()->register(new ContentRef(self::PAGE, '1'), 'guides/first-ride', 'First ride', 'guides');

        try {
            $categories->delete($guides->ref->id);
            self::fail('a category with a page in it was deleted');
        } catch (PathRefused $refused) {
            self::assertStringContainsString('still has', $refused->getMessage());
        }

        self::assertNotNull($this->registry()->find('guides'));
        self::assertNotNull($this->registry()->find('guides/first-ride'));
    }

    public function testAnEmptyCategoryIsDeleted(): void
    {
        $categories = $this->categories();
        $guides = $categories->create('Guides', 'guides', null);

        $categories->delete($guides->ref->id);

        self::assertNull($this->registry()->find('guides'));
        self::assertSame([], $categories->all());
    }

    public function testTheLastPartOfACategoryIsRefusedWithADot(): void
    {
        $this->expectException(PathRefused::class);
        $this->expectExceptionMessage('a dot');

        $this->categories()->create('Guides', 'guides.html', null);
    }

    public function testFilingUnderACategoryThatIsNotThereIsRefused(): void
    {
        $this->expectException(PathRefused::class);
        $this->expectExceptionMessage('no category');

        $this->categories()->create('Winter', 'winter', 'not-a-category');
    }

    /**
     * Where an address sits, said the way a form asks for it: which category,
     * and the last part. An address typed out in full before categories
     * existed has no such answer, and saying so is the only honest reply.
     */
    public function testAnAddressIsPlacedByItsCategoryAndItsLastPart(): void
    {
        $categories = $this->categories();
        $guides = $categories->create('Guides', 'guides', null);
        $this->registry()->register(new ContentRef(self::PAGE, '1'), 'guides/first-ride', 'First ride', 'guides');
        $this->registry()->register(new ContentRef(self::PAGE, '2'), 'about', 'About');
        $this->registry()->register(new ContentRef(self::PAGE, '3'), 'typed/out/by/hand', 'By hand');

        self::assertEquals(new Placement($guides->ref->id, 'first-ride'), $categories->placementOf('guides/first-ride'));
        self::assertEquals(new Placement(null, 'about'), $categories->placementOf('about'));
        self::assertNull($categories->placementOf('typed/out/by/hand'));
    }

    public function testASuggestionForACategorySkipsAnAddressAlreadyTakenButNotItsOwn(): void
    {
        $categories = $this->categories();
        $guides = $categories->create('Guides', 'guides', null);

        self::assertSame('guides-2', $categories->suggest('Guides', null, null));
        self::assertSame('guides', $categories->suggest('Guides', null, $guides->ref->id));
    }

    private function categories(): Categories
    {
        return $this->container()->getByType(Categories::class);
    }

    private function registry(): PathRegistry
    {
        return $this->container()->getByType(PathRegistry::class);
    }

    /** Core alone: categories are Core's, so no module has to be in the build for them to work. */
    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::coreAlone();
        Migrations::run($container);
        Tenants::enter($container);

        return $this->container = $container;
    }
}
