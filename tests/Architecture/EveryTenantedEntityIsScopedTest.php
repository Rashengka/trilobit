<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Tenancy\Shared;
use Trilobit\Core\Tenancy\TenancyRefused;
use Trilobit\Core\Tenancy\TenantFilter;
use Trilobit\Tests\Architecture\Fixtures\Tenancy\ForgottenThing;

/**
 * Every entity either carries its tenant or says out loud that it is one table
 * for the whole installation.
 *
 * This is the rule that has to hold for entities nobody has written yet, which
 * is why it is asked of the mapping rather than of a list. A query that forgot
 * the tenant does not fail - it answers with rows, and they belong to somebody
 * else - so the moment a new entity may be added without anybody noticing it
 * is unscoped, the whole dimension is decoration.
 *
 * The default is deny and that is the whole mechanism. An entity that nobody
 * thought about carries no tenant and no Trilobit\Core\Tenancy\Shared
 * attribute, so the filter cannot build a constraint for it and says so; this
 * asks the same question of every mapped entity at build time rather than at
 * the first query. Declaring an entity shared is possible and has to be
 * written down with the reason - which is the difference between a decision
 * and an omission.
 *
 * The rule is the filter's own decision rather than a second reading of the
 * mapping that could agree with it by accident: what is asked here is exactly
 * what Trilobit\Core\Tenancy\TenantFilter asks itself before it builds a
 * constraint. A rule that merely looked for a field called `tenant` would pass
 * on an entity the filter cannot in fact scope.
 */
#[CoversNothing]
final class EveryTenantedEntityIsScopedTest extends TestCase
{
    public function testEveryEntityIsEitherScopedByTheFilterOrDeclaredShared(): void
    {
        self::assertSame([], $this->unscopedIn(Mapping::ofTheApplication()));
    }

    /**
     * The application contains no such entity, so the assertion above would
     * hold just as well if this rule looked in the wrong place. Here the same
     * rule is run over the application's own entities plus three fixtures, one
     * of which is the mistake - and it has to report that one and only that
     * one, so that neither the tenanted fixture nor the declared-shared one is
     * swept up with it.
     */
    public function testTheRuleReportsAnEntityThatIsNeither(): void
    {
        $mapping = Mapping::inDirectory(
            __DIR__ . '/Fixtures/Tenancy',
            Bootstrap::rootDirectory() . '/src/Core/Domain',
        );

        self::assertSame(
            [ForgottenThing::class],
            $this->unscopedIn($mapping),
        );
    }

    /** A rule run over nothing reports nothing, so there has to be something to run it over. */
    public function testTheApplicationHasEntities(): void
    {
        self::assertNotSame([], Mapping::ofTheApplication());
    }

    /**
     * Declaring an entity shared is a decision, so it carries the reason for
     * it. An empty reason is the attribute used as a way past the rule rather
     * than as an answer to it.
     */
    public function testEverySharedEntitySaysWhyItIsShared(): void
    {
        $silent = [];
        foreach (Mapping::ofTheApplication() as $metadata) {
            foreach (new \ReflectionClass($metadata->getName())->getAttributes(Shared::class) as $attribute) {
                if (trim($attribute->newInstance()->because) === '') {
                    $silent[] = $metadata->getName();
                }
            }
        }

        self::assertSame([], $silent);
    }

    /**
     * The entities the plan names as tenanted really are, stated by name so
     * that taking the dimension off one of them is a failing test rather than
     * a passing one with less in it.
     */
    public function testTheTablesTheDimensionWasAddedForAreScoped(): void
    {
        $scoped = [];
        foreach (Mapping::ofTheApplication() as $metadata) {
            if (!TenantFilter::isShared($metadata->getName())) {
                $scoped[] = $metadata->getTableName();
            }
        }

        sort($scoped);

        self::assertSame(
            [
                'cms_menu_item',
                'cms_page',
                'core_content_path',
                'core_domain',
                'core_media_file',
                'core_tenant_membership',
            ],
            $scoped,
        );
    }

    /**
     * The names of the entities the filter cannot scope, in the order the
     * mapping is read.
     *
     * The two halves of the filter's own decision are asked directly rather
     * than through addFilterConstraint(), which quotes its parameter through
     * the connection and would therefore need a database running - and the
     * whole point of an architecture suite is that it does not. What is left
     * out is the sprintf() that puts the two together; that the constraint
     * really scopes the SQL is stated where a database is present, by
     * Trilobit\Tests\Integration\Tenancy\TenantScopedReadingTest.
     *
     * @param list<ClassMetadata<object>> $mapping
     *
     * @return list<string>
     */
    private function unscopedIn(array $mapping): array
    {
        $unscoped = [];
        foreach ($mapping as $metadata) {
            if (TenantFilter::isShared($metadata->getName())) {
                continue;
            }

            try {
                TenantFilter::tenantColumnOf($metadata);
            } catch (TenancyRefused) {
                $unscoped[] = $metadata->getName();
            }
        }

        return $unscoped;
    }
}
