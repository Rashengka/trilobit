<?php

declare(strict_types=1);

namespace Trilobit\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes the table the setup wizard claims an installation in: one row under
 * the one key there is, written first inside the transaction that makes the
 * installation's first administrator, so that of two visitors finishing at the
 * same moment the database lets only one through. See
 * Trilobit\Core\Domain\Setup\Completion (.ai/plans/23-instalace-na-zelene-louce.md).
 *
 * A new table and no ALTER TABLE, so there is nothing here to say how to run
 * without a lock; the table is InnoDB, the server's default engine, and empty.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff`. It is committed
 * as it came out - the description is added by hand, the statements are not
 * touched.
 */
final class Version20260913133248 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Makes the table the setup wizard claims an installation in, once.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE core_setup_completion (id INT NOT NULL, completed_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE core_setup_completion');
    }
}
