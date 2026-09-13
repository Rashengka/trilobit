<?php

declare(strict_types=1);

namespace Trilobit\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives each business the menus of its site, as rows of their own: what a
 * business saved about how a menu is put together - the order its
 * contributors stand in and which of them are drawn - has nowhere else to be
 * kept (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3, decided 2026-09-13).
 * Nothing saved is the default from code, so the new column is empty and there
 * is no data step here; the rows of the menus entries are already arranged
 * into are made by the next migration of the module that holds those entries.
 *
 * **The foreign key is copied rather than added in place, and without a
 * lock.** The table is InnoDB - the server's default engine, and the one the
 * CREATE TABLE above gets - and every ALTER TABLE here says how it is to run,
 * so that the server cannot choose a locking way on its own. MariaDB 11.8.9
 * refuses ALGORITHM=INPLACE for adding a foreign key, measured on 2026-09-13
 * against the server in compose.yaml on a table of this shape: "ERROR 1846:
 * ALGORITHM=INPLACE is not supported. Reason: Adding foreign keys needs
 * foreign_key_checks=OFF. Try ALGORITHM=COPY"; ALGORITHM=COPY, LOCK=NONE was
 * accepted on the same table, and dropping the key again was accepted
 * INPLACE. Switching the checks off is not this migration's to decide (the
 * reasoning is on Version20260913063908). The table is new and empty, so the
 * copy costs nothing; LOCK=NONE keeps the guarantee that a server which could
 * not copy without a lock refuses rather than takes one.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff`. It is committed
 * as it came out - the description above and the two clauses on each ALTER
 * TABLE are added by hand, the statements and their order are not touched.
 */
final class Version20260913105149 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gives each business the menus of its site, where what it saved about their arrangement is kept.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE core_menu (id INT AUTO_INCREMENT NOT NULL, composition JSON DEFAULT NULL, name VARCHAR(32) NOT NULL, tenant_id INT NOT NULL, INDEX IDX_4FE0F9A69033212A (tenant_id), UNIQUE INDEX uniq_menu_name (tenant_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE core_menu ADD CONSTRAINT FK_4FE0F9A69033212A FOREIGN KEY (tenant_id) REFERENCES core_tenant (id), ALGORITHM=COPY, LOCK=NONE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE core_menu DROP FOREIGN KEY FK_4FE0F9A69033212A, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('DROP TABLE core_menu');
    }
}
