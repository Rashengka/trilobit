<?php

declare(strict_types=1);

namespace Trilobit\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes the table each business keeps its rates of tax in: the rate in basis
 * points, a name, the country and kind it is, where it was taken from, whether
 * it is the business's default, and when it was archived. See
 * Trilobit\Core\Domain\Finance\TaxRate. Nothing points at it yet and a
 * business has no rate until somebody adds one, so there is no data step.
 *
 * At most one default in a business is held by default_key, a virtual column
 * the database works out as the business on the default row and as nothing
 * on every other, with a unique index over it - the way tenant_key holds the
 * codes of Trilobit\Core\Domain\User\Role. That the default is never
 * archived is held by the CHECK on is_default, generated from the column's
 * definition in the mapping.
 *
 * **The foreign key is copied rather than added in place, and without a
 * lock.** The table is InnoDB - the server's default engine, which the CREATE
 * TABLE above gets - and every ALTER TABLE here says how it is to run, so that
 * the server cannot choose a locking way on its own. MariaDB 11.8.9 refuses
 * ALGORITHM=INPLACE for adding a foreign key, measured on 2026-11-26 against
 * the server in compose.yaml on a table of this shape (the virtual column, its
 * unique index and the CHECK included), with foreign_key_checks on: "ERROR
 * 1846: ALGORITHM=INPLACE is not supported. Reason: Adding foreign keys needs
 * foreign_key_checks=OFF. Try ALGORITHM=COPY"; ALGORITHM=COPY, LOCK=NONE was
 * accepted on the same table, and dropping the key again was accepted
 * INPLACE. Switching the checks off is not this migration's to decide (the
 * reasoning is on Version20260913063908). The table is new and empty, so the
 * copy costs nothing; LOCK=NONE keeps the guarantee that a server which could
 * not copy without a lock refuses rather than takes one.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff
 * --namespace='Trilobit\Core\Migrations'`. It is committed as it came out - the
 * description above and the two clauses on each ALTER TABLE are added by hand,
 * the statements and their order are not touched.
 */
final class Version20261126151136 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Makes the table each business keeps its rates of tax in.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE core_tax_rate (id INT AUTO_INCREMENT NOT NULL, rate INT NOT NULL, template_key VARCHAR(64) DEFAULT NULL, is_default TINYINT NOT NULL CHECK (is_default = 0 OR archived_at IS NULL), default_key INT AS (IF(is_default, tenant_id, NULL)) VIRTUAL, archived_at DATETIME DEFAULT NULL, updated_at DATETIME NOT NULL, name VARCHAR(191) NOT NULL, country CHAR(2) DEFAULT NULL, kind VARCHAR(16) DEFAULT NULL, position INT NOT NULL, created_at DATETIME NOT NULL, tenant_id INT NOT NULL, INDEX IDX_7CA146629033212A (tenant_id), UNIQUE INDEX uniq_tax_rate_template (tenant_id, template_key), UNIQUE INDEX uniq_tax_rate_default (default_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE core_tax_rate ADD CONSTRAINT FK_7CA146629033212A FOREIGN KEY (tenant_id) REFERENCES core_tenant (id), ALGORITHM=COPY, LOCK=NONE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE core_tax_rate DROP FOREIGN KEY FK_7CA146629033212A, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('DROP TABLE core_tax_rate');
    }
}
