<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Doctrine\TableName;

/**
 * A foreign key never crosses the boundary between two switchable modules.
 *
 * This is the rule the whole idea of a switchable module rests on. A build
 * without Crm keeps the crm_ tables it already had, untouched and unreferenced;
 * the moment shop_order held a foreign key into crm_contact, switching Crm off
 * would leave the database with a constraint pointing at tables nobody in the
 * build knows about, and switching it back on would be a data repair rather
 * than a configuration change. A reference across that boundary is therefore
 * kept as a value - a type and an identifier with no constraint behind them.
 *
 * deptrac cannot see this. An association names its target as a string often
 * enough that a syntax tree is the wrong place to look; the mapping the
 * object-relational mapper actually ends up with is the right one.
 *
 * Which module an entity belongs to is taken from its table name, because that
 * is what the schema filter goes by too, and TablePrefixTest is what makes the
 * table name and the namespace agree. So a single rule is being checked from
 * two directions rather than two rules being kept in step.
 */
#[CoversNothing]
final class NoCrossModuleAssociationTest extends TestCase
{
    /** In every build, so a foreign key into it is safe from any module. */
    private const string ALWAYS_ENABLED = 'core';

    public function testNoAssociationCrossesTheBoundaryBetweenTwoModules(): void
    {
        self::assertSame([], $this->crossingsIn(Mapping::ofTheApplication()));
    }

    /**
     * The application contains no such association, so the assertion above
     * would hold just as well if this rule looked in the wrong place. Here the
     * same rule is run over three mapped entities that do contain one, and has
     * to report it - and only it, so that the association into Core is not
     * reported as a crossing.
     */
    public function testTheRuleReportsACrossingWhenThereIsOne(): void
    {
        self::assertSame(
            ['Trilobit\Tests\Architecture\Fixtures\CrossModule\ShopThing::$forbidden' => 'shop -> crm'],
            $this->crossingsIn(Mapping::inDirectory(__DIR__ . '/Fixtures/CrossModule')),
        );
    }

    /**
     * @param list<ClassMetadata<object>> $mapping
     *
     * @return array<string, string> entity and field => the boundary it crosses
     */
    private function crossingsIn(array $mapping): array
    {
        $moduleOfEntity = [];
        foreach ($mapping as $metadata) {
            $moduleOfEntity[$metadata->getName()] = TableName::moduleOf($metadata->getTableName());
        }

        $crossings = [];
        foreach ($mapping as $metadata) {
            $from = $moduleOfEntity[$metadata->getName()] ?? null;

            foreach ($metadata->getAssociationMappings() as $field => $association) {
                $to = $moduleOfEntity[$association->targetEntity] ?? null;

                self::assertNotNull($from, $metadata->getTableName() . ' belongs to no module');
                self::assertNotNull($to, $association->targetEntity . ' is not in this mapping');

                if ($from === $to || $to === self::ALWAYS_ENABLED) {
                    continue;
                }

                $crossings[sprintf('%s::$%s', $metadata->getName(), $field)] = sprintf('%s -> %s', $from, $to);
            }
        }

        ksort($crossings);

        return $crossings;
    }
}
