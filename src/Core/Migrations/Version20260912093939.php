<?php

declare(strict_types=1);

namespace Trilobit\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moves the pieces roles are assembled from onto the resources as they are
 * named now - paths under `app`, where the name is the tree.
 *
 * A piece is stored as the resource's value and a privilege, so a resource
 * that is renamed leaves every role holding a piece of it pointing at nothing:
 * this build drops such a piece when it reads it, and the role reaches nothing
 * without anything saying why. Only the resource part of a piece changes - the
 * privilege, and the star standing for the whole of a resource, are carried
 * over as they are.
 *
 * **This is data, not schema, and that is why it is written by hand.** The
 * class was made by `bin/trilobit migrations:generate`, which writes an empty
 * one; the mapping did not change, so there is nothing for `migrations:diff`
 * to derive, and core_role keeps every column it had. The only statements are
 * updates of core_role.permissions.
 *
 * **Down is the same rename the other way, exactly.** A row naming nothing
 * that moved is not written at all, in either direction, so a row comes back
 * as the very string it was rather than as an equal one. A piece that did not
 * exist before - `app:*`, say, written after the upgrade - is left as it is on
 * the way down: the earlier build drops a piece it does not know, which takes
 * that right away, the direction a doubt should fall.
 *
 * The names are written out here rather than read off
 * Trilobit\Core\Security\Resource. A migration says what was true when it was
 * written, and the enum will go on changing after it.
 */
final class Version20260912093939 extends AbstractMigration
{
    /** What each resource was called, and what it is called now. */
    private const array MOVED = [
        'administration' => 'app.administration',
        'account' => 'app.administration.account',
        'content' => 'app.administration.content',
        'redirection' => 'app.redirection',
    ];

    /** As in Trilobit\Core\Security\Grant, where a piece is written: the resource, this, and the privilege. */
    private const string SEPARATOR = ':';

    public function getDescription(): string
    {
        return 'Moves the pieces roles are assembled from onto the resources named as paths under app.';
    }

    public function up(Schema $schema): void
    {
        $this->rename(self::MOVED);
    }

    public function down(Schema $schema): void
    {
        $this->rename(array_flip(self::MOVED));
    }

    /**
     * Rewrites every role holding a piece of a resource in $names, and no
     * other.
     *
     * The column is read and written through the same type the entity maps it
     * with, so a rewritten row is stored the way the application stores one.
     *
     * @param array<string, string> $names by the name a piece may have, the
     *     name it is to have
     */
    private function rename(array $names): void
    {
        $json = Type::getType(Types::JSON);
        $platform = $this->connection->getDatabasePlatform();

        foreach ($this->connection->fetchAllAssociative('SELECT id, permissions FROM core_role ORDER BY id') as $row) {
            $pieces = $json->convertToPHPValue($row['permissions'], $platform);
            if (!is_array($pieces)) {
                continue;
            }

            $renamed = array_map(
                static fn(mixed $piece): mixed => is_string($piece) ? self::renamed($piece, $names) : $piece,
                $pieces,
            );

            if ($renamed === $pieces) {
                continue;
            }

            $this->addSql(
                'UPDATE core_role SET permissions = ? WHERE id = ?',
                [$json->convertToDatabaseValue($renamed, $platform), $row['id']],
            );
        }
    }

    /** @param array<string, string> $names */
    private static function renamed(string $piece, array $names): string
    {
        $separator = strrpos($piece, self::SEPARATOR);
        if ($separator === false) {
            return $piece;
        }

        $resource = substr($piece, 0, $separator);

        return isset($names[$resource]) ? $names[$resource] . substr($piece, $separator) : $piece;
    }
}
