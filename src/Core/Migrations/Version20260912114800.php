<?php

declare(strict_types=1);

namespace Trilobit\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes the role `app:account --tenant` gives the person administering a
 * business into the owner of that business, holding the whole of the
 * application.
 *
 * The earlier build called it `administrator` and listed every pair its
 * structure offered, rewriting the list on every run of the command so that a
 * section added later would reach it. The owner holds `app:*` instead, which
 * takes in every section by itself - and is honoured on no other role, so the
 * code has to change with the pieces: `app:*` under the old code would be
 * dropped.
 *
 * **This is data, not schema, and that is why it is written by hand.** The
 * class was made by `bin/trilobit migrations:generate`, which writes an empty
 * one; the mapping did not change, so there is nothing for `migrations:diff`
 * to derive, and core_role keeps every column it had. The only statements are
 * updates of one row of core_role.
 *
 * **Down gives the earlier build what it wrote.** Whatever the row held
 * before is not kept anywhere - the command rewrote it on every run, so it was
 * never anybody's choice - and the earlier build expects its administrator to
 * hold every pair its structure offered. Those pairs are written out below
 * rather than read off Trilobit\Core\Security\PermissionStructure: a migration
 * says what was true when it was written, and the structure will go on
 * changing after it.
 */
final class Version20260912114800 extends AbstractMigration
{
    /** @var array{code: string, name: string, permissions: list<string>} */
    private const array ADMINISTRATOR = [
        'code' => 'administrator',
        'name' => 'Administrator',
        'permissions' => [
            'app:view',
            'app.administration:view',
            'app.administration.account:view',
            'app.administration.account:add',
            'app.administration.account:edit',
            'app.administration.account:delete',
            'app.administration.account:purge',
            'app.administration.content:view',
            'app.administration.content:add',
            'app.administration.content:edit',
            'app.administration.content:delete',
            'app.administration.content:purge',
            'app.administration.content:export',
            'app.administration.content:change_priority',
            'app.redirection:view',
            'app.redirection:force_redirect',
        ],
    ];

    /**
     * As in Trilobit\Core\Domain\User\Role::OWNER and
     * Trilobit\Core\Console\AccountCommand, written out for the same reason
     * as the pairs above.
     *
     * @var array{code: string, name: string, permissions: list<string>}
     */
    private const array OWNER = [
        'code' => 'owner',
        'name' => 'Owner',
        'permissions' => ['app:*'],
    ];

    public function getDescription(): string
    {
        return 'Makes the administrator of a business its owner, holding the whole of the application.';
    }

    public function up(Schema $schema): void
    {
        $this->become(self::ADMINISTRATOR['code'], self::OWNER);
    }

    public function down(Schema $schema): void
    {
        $this->become(self::OWNER['code'], self::ADMINISTRATOR);
    }

    /**
     * Rewrites the role under $code as $role, and no other row.
     *
     * The column is written through the same type the entity maps it with, so
     * the row is stored the way the application stores one.
     *
     * @param array{code: string, name: string, permissions: list<string>} $role
     */
    private function become(string $code, array $role): void
    {
        $this->addSql(
            'UPDATE core_role SET code = ?, name = ?, permissions = ? WHERE code = ?',
            [
                $role['code'],
                $role['name'],
                Type::getType(Types::JSON)->convertToDatabaseValue(
                    $role['permissions'],
                    $this->connection->getDatabasePlatform(),
                ),
                $code,
            ],
        );
    }
}
