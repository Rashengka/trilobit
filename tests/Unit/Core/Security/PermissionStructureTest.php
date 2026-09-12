<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Security;

use Nette\Security\Permission;
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

    public function testWhatAResourceOffersIsWhatTheFileSays(): void
    {
        $structure = PermissionStructure::of(Bootstrap::rootDirectory());

        self::assertTrue($structure->offers(Resource::Content, Privilege::Edit));
        self::assertFalse($structure->offers(Resource::Content, Privilege::Send));
    }

    /**
     * A resource is registered after what it falls under, and inheritance then
     * does the work: one rule about the administration answers for a section
     * of it. Asked of a real Nette\Security\Permission rather than of the
     * structure's own idea of it, because the claim is about what the
     * framework does with what it is given.
     */
    public function testARuleOnWhatAResourceFallsUnderAnswersForIt(): void
    {
        $access = new Permission();
        PermissionStructure::of(Bootstrap::rootDirectory())->addResourcesTo($access);

        $access->addRole('administrator');
        $access->allow('administrator', Resource::Administration->value, Privilege::View->value);

        self::assertTrue($access->isAllowed('administrator', Resource::Content->value, Privilege::View->value));
        self::assertFalse($access->isAllowed('administrator', Resource::Redirection->value, Privilege::View->value));
    }

    /**
     * Everything under a resource, however deep, because that is how far a
     * rule on it reaches in Nette. The shipped file is one level deep, so the
     * second level is written here: a walk that stopped at the children would
     * agree with the shipped file and be wrong the day a section gets one of
     * its own.
     */
    public function testEverythingUnderAResourceIsFoundHoweverDeep(): void
    {
        $structure = $this->structureOf(
            $this->describing(Resource::Administration->value)
                . $this->describing(Resource::Content->value, Resource::Administration->value)
                . $this->describing(Resource::Account->value, Resource::Content->value)
                . $this->describing(Resource::Redirection->value),
        );

        self::assertEqualsCanonicalizing(
            [Resource::Content, Resource::Account],
            $structure->descendantsOf(Resource::Administration),
        );
        self::assertSame([Resource::Account], $structure->descendantsOf(Resource::Content));
        self::assertSame([], $structure->descendantsOf(Resource::Account));
        self::assertSame([], $structure->descendantsOf(Resource::Redirection));
    }

    /** The shipped file, which is what every access list is composed from. */
    public function testTheSectionsOfTheAdministrationFallUnderIt(): void
    {
        $structure = PermissionStructure::of(Bootstrap::rootDirectory());

        self::assertEqualsCanonicalizing(
            [Resource::Account, Resource::Content],
            $structure->descendantsOf(Resource::Administration),
        );
        self::assertSame([], $structure->descendantsOf(Resource::Redirection));
    }

    /**
     * Everything a resource falls under, nearest first and however high, because
     * a right on it opens every one of them. Two levels are written for the
     * same reason as above: a walk that stopped at the parent would agree with
     * the shipped file.
     */
    public function testEverythingAResourceFallsUnderIsFoundHoweverHigh(): void
    {
        $structure = $this->structureOf(
            $this->describing(Resource::Administration->value)
                . $this->describing(Resource::Content->value, Resource::Administration->value)
                . $this->describing(Resource::Account->value, Resource::Content->value)
                . $this->describing(Resource::Redirection->value),
        );

        self::assertSame([Resource::Content, Resource::Administration], $structure->ancestorsOf(Resource::Account));
        self::assertSame([Resource::Administration], $structure->ancestorsOf(Resource::Content));
        self::assertSame([], $structure->ancestorsOf(Resource::Administration));
        self::assertSame([], $structure->ancestorsOf(Resource::Redirection));
    }

    /**
     * Which resources may be granted whole. The administration and its
     * content may; the accounts may not, because a new privilege on them is
     * one nobody should come to hold without having been given it by name.
     */
    public function testTheShippedStructureOffersTheWholeOfOnlyWhatItSaysSo(): void
    {
        $structure = PermissionStructure::of(Bootstrap::rootDirectory());

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
        $this->expectExceptionMessageMatches("#'administration'#");

        $this->structureOf(
            $this->everyResourceExcept(Resource::Administration)
                . "\nadministration:\n    bundle: everything\n    privileges: [view]\n",
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
                . "\nadministration:\n    bundel: true\n    privileges: [view]\n",
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
        $this->expectExceptionMessageMatches("#'administration'.*view#");

        $this->structureOf(
            "administration:\n    privileges: [edit]\n\n"
                . $this->describing(Resource::Content->value, Resource::Administration->value)
                . $this->describing(Resource::Account->value)
                . $this->describing(Resource::Redirection->value),
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
                . "\nadministration:\n    privileges: [view, unpublish]\n",
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
        $this->expectExceptionMessageMatches("#'administration'#");

        $this->structureOf($this->everyResourceExcept(Resource::Administration));
    }

    public function testAResourceWithNothingToAskOfItIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("#'administration'#");

        $this->structureOf($this->everyResourceExcept(Resource::Administration) . "\nadministration:\n    privileges: []\n");
    }

    public function testFallingUnderSomethingThatIsNotAResourceIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("#'billing'#");

        $this->structureOf(
            $this->everyResourceExcept(Resource::Administration)
                . "\nadministration:\n    parent: billing\n    privileges: [view]\n",
        );
    }

    public function testFallingUnderItselfIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#falls under itself#');

        $this->structureOf(
            $this->everyResourceExcept(Resource::Administration)
                . "\nadministration:\n    parent: administration\n    privileges: [view]\n",
        );
    }

    /**
     * A circle passes every check made while the file is read - each name in
     * it is a resource that really exists - so it is caught where it shows,
     * which is when nothing can be registered first.
     */
    public function testResourcesFallingUnderEachOtherInACircleAreRefused(): void
    {
        $file = '';
        foreach (Resource::cases() as $index => $resource) {
            $under = Resource::cases()[($index + 1) % count(Resource::cases())];
            $file .= $this->describing($resource->value, $under->value);
        }

        $structure = $this->structureOf($file);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#in a circle#');

        $structure->addResourcesTo(new Permission());
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
    private function describing(string $resource, ?string $under = null): string
    {
        $described = $resource . ":\n";
        if ($under !== null) {
            $described .= '    parent: ' . $under . "\n";
        }

        return $described . "    privileges: [view]\n\n";
    }
}
