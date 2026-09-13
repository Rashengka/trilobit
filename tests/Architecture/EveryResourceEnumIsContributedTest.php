<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Nette\Utils\Finder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Security\PermissionStructure;
use Trilobit\Core\Security\Resource;
use Trilobit\Core\Security\ResourceName;
use Trilobit\Tests\Architecture\Fixtures\Resources\UncontributedResource;
use Trilobit\Tests\Double\Security\ResourcesOf;

/**
 * Every enum of resources in the source is one some build brings.
 *
 * Trilobit\Tests\Architecture\EveryPermissionQuestionIsPredefinedTest reads the
 * questions by their enums, and it knows an enum only when a build brings it.
 * An enum of resources no Trilobit\Core\Security\ResourceProvider hands over
 * is therefore one whose questions that rule would pass over without a word -
 * a guard met by a way round it rather than by the code being right. The
 * question would raise at run time, because the pair is not offered, but only
 * on the page that asks it: too late, and only for whoever opens that page.
 *
 * So the other half is asked here, at build time, over the source: every enum
 * under src/ implementing Trilobit\Core\Security\ResourceName has to be among
 * the resources of the widest build. Like its sibling, it is held from both
 * sides - over fixtures it has to report an enum nobody brings, and stop
 * reporting it once something does.
 */
#[CoversNothing]
final class EveryResourceEnumIsContributedTest extends TestCase
{
    private const string FIXTURE_NAMESPACE = 'Trilobit\\Tests\\Architecture\\Fixtures\\Resources\\';

    public function testEveryEnumOfResourcesInTheSourceIsBroughtByTheWidestBuild(): void
    {
        self::assertSame(
            [],
            $this->notBroughtUnder(Bootstrap::rootDirectory() . '/src', 'Trilobit\\', WidestBuild::permissionStructure()),
        );
    }

    /**
     * The rule above passes over a source in which it finds no enum at all, so
     * it has to be seen finding them: Core's, and at least one module's.
     */
    public function testTheRuleFindsTheEnumsOfResourcesTheSourceHas(): void
    {
        $enums = $this->enumsUnder(Bootstrap::rootDirectory() . '/src', 'Trilobit\\');

        self::assertContains(Resource::class, $enums);
        self::assertGreaterThan(1, count($enums), 'a module brings resources of its own, so there is more than Core\'s enum');
    }

    public function testTheRuleReportsAnEnumNoBuildBrings(): void
    {
        self::assertSame(
            [UncontributedResource::class],
            $this->notBroughtUnder($this->fixtures(), self::FIXTURE_NAMESPACE, PermissionStructure::of(Bootstrap::rootDirectory(), [])),
        );
    }

    public function testTheRuleReportsNothingOnceTheEnumIsBrought(): void
    {
        $structure = PermissionStructure::of(
            Bootstrap::rootDirectory(),
            [new ResourcesOf(UncontributedResource::cases(), $this->fixtures() . '/permissions.neon')],
        );

        self::assertSame([], $this->notBroughtUnder($this->fixtures(), self::FIXTURE_NAMESPACE, $structure));
    }

    /** @return list<string> every enum of resources under $directory that $structure does not bring */
    private function notBroughtUnder(string $directory, string $namespace, PermissionStructure $structure): array
    {
        $brought = array_map(static fn(ResourceName $resource): string => $resource::class, $structure->resources());

        return array_values(array_diff($this->enumsUnder($directory, $namespace), $brought));
    }

    /**
     * Every enum under $directory implementing ResourceName, by its whole
     * name, found by the file it is in - the name the autoloader expects there.
     *
     * @return list<string> sorted, so that a report reads the same twice
     */
    private function enumsUnder(string $directory, string $namespace): array
    {
        $enums = [];
        foreach (Finder::findFiles('*.php')->from($directory) as $file) {
            $class = $namespace . str_replace('/', '\\', substr((string) $file, strlen($directory) + 1, -strlen('.php')));
            if (enum_exists($class) && is_subclass_of($class, ResourceName::class)) {
                $enums[] = $class;
            }
        }

        sort($enums);

        return $enums;
    }

    private function fixtures(): string
    {
        return __DIR__ . '/Fixtures/Resources';
    }
}
