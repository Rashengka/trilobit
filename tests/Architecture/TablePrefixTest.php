<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Doctrine\TableName;
use Trilobit\Core\Module\ModuleList;

/**
 * Every table says which module it belongs to, in its name.
 *
 * That is not decoration. A build without Crm still has crm_ tables sitting in
 * the customer's database, and the only thing standing between them and a
 * generated DROP TABLE is a filter that decides, from the name alone, which
 * tables this build is allowed to see. A table whose name does not carry its
 * module is a table that filter cannot place - so the naming rule and the
 * filter are one mechanism, and this is the half of it a test can state.
 *
 * The module a table belongs to is worked out here from the namespace of the
 * entity rather than from the name being checked, so that the two have to
 * agree rather than merely be consistent with each other.
 */
#[CoversNothing]
final class TablePrefixTest extends TestCase
{
    /** The module every build contains, and the one Core's own tables belong to. */
    private const string ALWAYS_ENABLED = 'core';

    public function testEveryTableCarriesThePrefixOfItsModule(): void
    {
        $wrong = [];
        foreach (Mapping::ofTheApplication() as $metadata) {
            $expected = TableName::prefixOf($this->moduleOf($metadata));
            if (!str_starts_with($metadata->getTableName(), $expected)) {
                $wrong[$metadata->getName()] = sprintf('%s does not start with %s', $metadata->getTableName(), $expected);
            }
        }

        self::assertSame([], $wrong);
    }

    public function testTheNameAloneSaysWhichModuleATableBelongsTo(): void
    {
        $disagreeing = [];
        foreach (Mapping::ofTheApplication() as $metadata) {
            $fromTheName = TableName::moduleOf($metadata->getTableName());
            if ($fromTheName !== $this->moduleOf($metadata)) {
                $disagreeing[$metadata->getName()] = sprintf(
                    '%s reads as module %s',
                    $metadata->getTableName(),
                    $fromTheName ?? 'nothing',
                );
            }
        }

        self::assertSame([], $disagreeing);
    }

    /**
     * The mapping is read from a build with every module switched on, so every
     * entity has to belong to a module that is declared - or to Core, which is
     * not declared because it cannot be switched off.
     */
    public function testEveryEntityBelongsToADeclaredModuleOrToCore(): void
    {
        $root = Bootstrap::rootDirectory();
        $known = ModuleList::fromNeon($root . '/config/modules.neon', $root)->names();
        $known[] = self::ALWAYS_ENABLED;

        $strangers = [];
        foreach (Mapping::ofTheApplication() as $metadata) {
            $module = $this->moduleOf($metadata);
            if (!in_array($module, $known, true)) {
                $strangers[$metadata->getName()] = $module;
            }
        }

        self::assertSame([], $strangers);
    }

    /** There is at least one entity to check; an empty mapping would pass everything above. */
    public function testTheApplicationHasEntities(): void
    {
        self::assertNotSame([], Mapping::ofTheApplication());
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function moduleOf(ClassMetadata $metadata): string
    {
        $name = $metadata->getName();
        self::assertMatchesRegularExpression('#^Trilobit\\\\[A-Z][A-Za-z0-9]*\\\\#', $name, $name . ' is not one of ours');

        return strtolower(explode('\\', $name)[1]);
    }
}
