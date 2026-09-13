<?php

declare(strict_types=1);

namespace Trilobit\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives a role the business it belongs to, and makes its code unique within
 * that business and among the roles of the application rather than across
 * the whole installation.
 *
 * A role without a business is the application's, and every row that exists
 * is one: nothing before this build composed a role for a business, so the
 * empty column the rows are given is the right thing to say about each of
 * them. There is no data step.
 *
 * The code is unique together with tenant_key, which the database works out as
 * the business or 0 - not together with the business itself, whose NULL MariaDB
 * would let in twice. Why, is on Trilobit\Core\Domain\User\Role. The unique
 * index on the code alone is dropped because this one replaces it.
 *
 * **Two statements are copied rather than altered in place, and neither takes
 * a lock.** Every ALTER TABLE here says how it is to run, so that the server
 * cannot choose a locking way on its own; the table is InnoDB. MariaDB 11.8
 * refuses ALGORITHM=INPLACE on two of them, measured against the server in
 * compose.yaml before this was written:
 *
 * - adding the virtual column together with the business column in one
 *   statement - "INPLACE ADD or DROP of virtual columns cannot be combined with
 *   other ALTER TABLE actions", and the statement is Doctrine's to shape;
 * - adding the foreign key - "Adding foreign keys needs
 *   foreign_key_checks=OFF", and switching the checks off is not this
 *   migration's to decide.
 *
 * Those two run as ALGORITHM=COPY, LOCK=NONE: the online copy MariaDB has had
 * since 11.2, which every supported server - MariaDB 11 LTS, see the README -
 * has. LOCK=NONE keeps the guarantee: a server that could not copy without a
 * lock refuses rather than taking one. The table holds a handful of rows, so
 * the copy is measured in milliseconds. Every other ALTER TABLE runs
 * ALGORITHM=INPLACE, LOCK=NONE. This was chosen on 2026-09-13 over switching
 * the checks off and over a maintenance window.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff`. It is committed
 * as it came out - the description above and the two clauses on each ALTER
 * TABLE are added by hand, the statements and their order are not touched,
 * because what makes a migration trustworthy is that it is provably what
 * Doctrine derived from the mapping rather than what somebody believed it
 * should be.
 */
final class Version20260913063908 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gives a role the business it belongs to, and makes its code unique within that business and among the application\'s roles.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_658C495F77153098 ON core_role');
        $this->addSql('ALTER TABLE core_role ADD tenant_key INT AS (COALESCE(tenant_id, 0)) VIRTUAL, ADD tenant_id INT DEFAULT NULL, ALGORITHM=COPY, LOCK=NONE');
        $this->addSql('ALTER TABLE core_role ADD CONSTRAINT FK_658C495F9033212A FOREIGN KEY (tenant_id) REFERENCES core_tenant (id), ALGORITHM=COPY, LOCK=NONE');
        $this->addSql('CREATE INDEX IDX_658C495F9033212A ON core_role (tenant_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_role_code ON core_role (tenant_key, code)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE core_role DROP FOREIGN KEY FK_658C495F9033212A, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('DROP INDEX IDX_658C495F9033212A ON core_role');
        $this->addSql('DROP INDEX uniq_role_code ON core_role');
        $this->addSql('ALTER TABLE core_role DROP tenant_key, DROP tenant_id, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_658C495F77153098 ON core_role (code)');
    }
}
