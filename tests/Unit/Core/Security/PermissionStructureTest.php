<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Security;

use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Security\PermissionStructure;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * The pieces roles are assembled from, as the file says them and as an access
 * list ends up holding them.
 *
 * Most of what is asserted here is refusal, and that is the shape of the
 * subject: every one of these mistakes produces a working application that
 * answers a question wrongly, so the only moment they can be seen is the
 * moment the file is read.
 */
#[CoversClass(PermissionStructure::class)]
final class PermissionStructureTest extends TestCase
{
    private string $directory = '';

    protected function tearDown(): void
    {
        if ($this->directory !== '') {
            FileSystem::delete($this->directory);
            $this->directory = '';
        }
    }

    /**
     * The file this build ships has something to say about every resource -
     * which is what lets registration walk the enum and never a list beside
     * it.
     */
    public function testTheShippedStructureDescribesEveryResource(): void
    {
        $structure = PermissionStructure::of(Bootstrap::rootDirectory());

        foreach (Resource::cases() as $resource) {
            self::assertNotSame([], $structure->privilegesOf($resource), $resource->value);
        }
    }

    /**
     * What everything falls under is the application, found in the names
     * rather than named: the whole of it is the owner's, so it is the one
     * resource whose answer decides who that piece is reserved for.
     */
    public function testWhatEverythingFallsUnderIsTheApplication(): void
    {
        $structure = PermissionStructure::of(Bootstrap::rootDirectory());

        self::assertSame(Resource::App, $structure->root());

        foreach (Resource::cases() as $resource) {
            if ($resource !== Resource::App) {
                self::assertContains(Resource::App, $structure->ancestorsOf($resource), $resource->value);
            }
        }
    }

    public function testWhatAResourceOffersIsWhatTheFileSays(): void
    {
        $structure = PermissionStructure::of(Bootstrap::rootDirectory());

        self::assertTrue($structure->offers(Resource::Content, Privilege::Edit));
        self::assertFalse($structure->offers(Resource::Content, Privilege::Send));
    }

    /**
     * The tree is in the names: a resource falls under its name up to the last
     * dot. The shipped tree is three levels deep, so a walk that stopped at
     * the parent or at the children would be caught here.
     */
    public function testTheTreeIsReadFromTheDotsInTheNames(): void
    {
        $structure = PermissionStructure::of(Bootstrap::rootDirectory());

        self::assertSame([Resource::Administration, Resource::App], $structure->ancestorsOf(Resource::Content));
        self::assertSame([Resource::Administration, Resource::App], $structure->ancestorsOf(Resource::Account));
        self::assertSame([Resource::App], $structure->ancestorsOf(Resource::Administration));
        self::assertSame([Resource::App], $structure->ancestorsOf(Resource::Redirection));
        self::assertSame([], $structure->ancestorsOf(Resource::App));

        self::assertEqualsCanonicalizing(
            [Resource::Administration, Resource::Account, Resource::Content, Resource::Redirection],
            $structure->descendantsOf(Resource::App),
        );
        self::assertEqualsCanonicalizing(
            [Resource::Account, Resource::Content],
            $structure->descendantsOf(Resource::Administration),
        );
        self::assertSame([], $structure->descendantsOf(Resource::Content));
        self::assertSame([], $structure->descendantsOf(Resource::Redirection));
    }

    /**
     * Which resources may be granted whole. The application, the
     * administration and its content may; the accounts may not, because a new
     * privilege on them is one nobody should come to hold without having been
     * given it by name.
     */
    public function testTheShippedStructureOffersTheWholeOfOnlyWhatItSaysSo(): void
    {
        $structure = PermissionStructure::of(Bootstrap::rootDirectory());

        self::assertTrue($structure->offersBundle(Resource::App));
        self::assertTrue($structure->offersBundle(Resource::Administration));
        self::assertTrue($structure->offersBundle(Resource::Content));
        self::assertFalse($structure->offersBundle(Resource::Account));
        self::assertFalse($structure->offersBundle(Resource::Redirection));
    }

    /** Not saying is saying no - the direction a new privilege should fall. */
    public function testAResourceThatSaysNothingAboutABundleOffersNone(): void
    {
        $structure = $this->structureOf($this->everyResourceExcept());

        self::assertFalse($structure->offersBundle(Resource::Administration));
    }

    /**
     * Anything but yes or no is refused rather than read as one of them. A
     * bundle is the widest thing the file can say, and a value that is read
     * as yes by accident gives away every privilege the resource will ever
     * have.
     */
    public function testABundleThatIsNeitherYesNorNoIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("#'app\\.administration'#");

        $this->structureOf(
            $this->everyResourceExcept(Resource::Administration)
                . Resource::Administration->value . ":\n    bundle: everything\n    privileges: [view]\n",
        );
    }

    /**
     * A key the file does not know is refused, because the one it would most
     * likely be is a misspelt bundle - and that is read as no bundle, a
     * mistake whose only symptom is a right somebody quietly never gets.
     */
    public function testAKeyTheFileDoesNotKnowIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#bundel#');

        $this->structureOf(
            $this->everyResourceExcept(Resource::Administration)
                . Resource::Administration->value . ":\n    bundel: true\n    privileges: [view]\n",
        );
    }

    /**
     * What a resource falls under is its name, so a key saying it as well is
     * a second sentence that could disagree with the first - and the one read
     * would be the name. It is refused like any other key nobody reads.
     */
    public function testWhatAResourceFallsUnderCannotBeSaidBesideItsName(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("#'app\\.administration' by parent#");

        $this->structureOf(
            $this->everyResourceExcept(Resource::Administration)
                . Resource::Administration->value . ":\n    parent: app\n    privileges: [view]\n",
        );
    }

    /**
     * Something falls under a resource, so a right on it opens that resource -
     * and opening is `view`. A resource that does not offer it would be a
     * door nobody could be given, and everybody holding a piece under it would
     * be let into nothing without a word.
     */
    public function testAResourceSomethingFallsUnderHasToOfferView(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("#falls under 'app', which does not offer view#");

        $this->structureOf(
            $this->everyResourceExcept(Resource::App) . Resource::App->value . ":\n    privileges: [edit]\n",
        );
    }

    public function testAResourceThisBuildDoesNotHaveIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("#'invoicing'#");

        $this->structureOf("invoicing:\n    privileges: [view]\n");
    }

    /**
     * The one a framework would never catch: Nette checks a role and a
     * resource and says nothing about a privilege, so a name nobody has is a
     * rule nothing will ever match.
     */
    public function testAPrivilegeThisBuildDoesNotHaveIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("#'unpublish'#");

        $this->structureOf(
            $this->everyResourceExcept(Resource::Administration)
                . Resource::Administration->value . ":\n    privileges: [view, unpublish]\n",
        );
    }

    /**
     * A resource left out of the file is not a resource with nothing to offer;
     * it is a resource that would be registered with nothing that may be asked
     * of it, and every question about it answered no.
     */
    public function testAResourceLeftOutOfTheFileIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("#'app\\.administration'#");

        $this->structureOf($this->everyResourceExcept(Resource::Administration));
    }

    public function testAResourceWithNothingToAskOfItIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("#'app\\.administration'#");

        $this->structureOf(
            $this->everyResourceExcept(Resource::Administration)
                . Resource::Administration->value . ":\n    privileges: []\n",
        );
    }

    public function testAFileThatIsNotThereIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#does not say what may be asked about#');

        PermissionStructure::fromNeon(sys_get_temp_dir() . '/trilobit-no-such-permissions.neon');
    }

    private function structureOf(string $neon): PermissionStructure
    {
        $this->directory = sys_get_temp_dir() . '/trilobit-permissions-' . bin2hex(random_bytes(6));
        $file = $this->directory . '/permissions.neon';
        FileSystem::write($file, $neon);

        return PermissionStructure::fromNeon($file);
    }

    /**
     * Every resource but the named ones, each offering one privilege, so that
     * a test about one mistake is not also a test about everything it left
     * out.
     */
    private function everyResourceExcept(Resource ...$left): string
    {
        $file = '';
        foreach (Resource::cases() as $resource) {
            if (!in_array($resource, $left, true)) {
                $file .= $this->describing($resource->value);
            }
        }

        return $file;
    }

    /**
     * One resource as the file writes it.
     *
     * Put together out of pieces rather than with a format string, because
     * "%s:" followed by an escaped newline reads to the leak guard as a
     * Windows path and it says so - rightly, by its own rule.
     */
    private function describing(string $resource): string
    {
        return $resource . ":\n    privileges: [view]\n\n";
    }
}
