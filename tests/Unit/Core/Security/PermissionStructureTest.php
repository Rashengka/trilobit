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
