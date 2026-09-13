<?php

declare(strict_types=1);

namespace Trilobit\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives an arranged menu entry the menu it belongs to as a row of Core's,
 * beside the name it has held it by until now - the first of three steps
 * (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3, decided 2026-09-13).
 *
 * The new column is empty and allowed to be, because the rows it has to
 * point at do not exist yet: the next migration makes a menu for every name
 * the entries of a business use and points each entry at it, and the one
 * after that drops the name and requires the menu. Doctrine generates the
 * first and the last from the mapping; the one between is data, and no
 * mapping describes it.
 *
 * **The foreign key takes a shared lock, and it is the one statement here
 * that does - decided on 2026-09-13.** Every ALTER TABLE here says
 * how it is to run; the table is InnoDB. Measured on 2026-09-13 against the
 * server in compose.yaml (MariaDB 11.8.9), on a table of this shape carrying
 * the same foreign keys - two of them ON DELETE CASCADE, to the page and to
 * the parent entry:
 *
 * - the new column, ALGORITHM=INPLACE, LOCK=NONE: accepted;
 * - the foreign key, ALGORITHM=INPLACE, LOCK=NONE: refused, "ERROR 1846:
 *   Adding foreign keys needs foreign_key_checks=OFF" - it is accepted with
 *   the checks off, and switching them off is not this migration's to decide
 *   (the reasoning is on Trilobit\Core\Migrations\Version20260913063908);
 * - the foreign key, ALGORITHM=COPY, LOCK=NONE: refused, "ERROR 1846:
 *   LOCK=NONE is not supported. Reason: ON DELETE CASCADE. Try LOCK=SHARED" -
 *   an online copy cannot carry a table whose keys cascade;
 * - the foreign key, ALGORITHM=COPY, LOCK=SHARED: accepted;
 * - taking the key and the column back, ALGORITHM=INPLACE, LOCK=NONE: accepted.
 *
 * So the foreign key is ALGORITHM=COPY, LOCK=SHARED: while the table is copied
 * it can be read and cannot be written. It holds a handful of entries per
 * business - ten in the database `app:seed` makes for two - so the copy is
 * measured in milliseconds, and the lock is said here rather than left to the
 * server to choose. Everything else stays ALGORITHM=INPLACE, LOCK=NONE.
 *
 * The first measurement of this was taken on a copy made with CREATE TABLE
 * ... LIKE, which leaves the foreign keys behind, and it said COPY/NONE would
 * do; the migration then failed half way on the real table. The numbers above
 * are from a copy with the keys on it.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff`. It is committed
 * as it came out - the description above and the two clauses on each ALTER
 * TABLE are added by hand, the statements and their order are not touched.
 */
final class Version20260913105345 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gives an arranged menu entry the menu it belongs to, beside the name it has held it by until now.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cms_menu_item ADD menu_id INT DEFAULT NULL, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE cms_menu_item ADD CONSTRAINT FK_1432B53DCCD7E912 FOREIGN KEY (menu_id) REFERENCES core_menu (id), ALGORITHM=COPY, LOCK=SHARED');
        $this->addSql('CREATE INDEX IDX_1432B53DCCD7E912 ON cms_menu_item (menu_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cms_menu_item DROP FOREIGN KEY FK_1432B53DCCD7E912, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('DROP INDEX IDX_1432B53DCCD7E912 ON cms_menu_item');
        $this->addSql('ALTER TABLE cms_menu_item DROP menu_id, ALGORITHM=INPLACE, LOCK=NONE');
    }
}
