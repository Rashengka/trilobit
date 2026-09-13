<?php

declare(strict_types=1);

namespace Trilobit\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the name an arranged menu entry held its menu by, and requires the
 * menu instead - the last of three steps
 * (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3, decided 2026-09-13).
 *
 * By now every entry points at the menu of its own business under the name it
 * had (the data step before this one), so requiring the menu refuses nothing
 * that exists, and the name has nothing left to say that the menu does not.
 * Taken back, the name comes back empty and the data step before this fills
 * it in again from the menu the entry points at.
 *
 * **Both ways run in place and without a lock.** The table is InnoDB, and
 * both statements were measured on 2026-09-13 against the server in
 * compose.yaml (MariaDB 11.8.9), in exactly the shape Doctrine generated
 * them, on a table of this shape carrying the same foreign keys - the new one
 * to the menu included, and two that cascade: dropping the name while making
 * the menu required, ALGORITHM=INPLACE, LOCK=NONE, accepted with every entry
 * pointing at a menu; adding the name back while letting the menu be empty,
 * the same, accepted.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff`. It is committed
 * as it came out - the description above and the two clauses on each ALTER
 * TABLE are added by hand, the statements and their order are not touched.
 */
final class Version20260913110711 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drops the name an arranged menu entry held its menu by, and requires the menu instead.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cms_menu_item DROP menu, CHANGE menu_id menu_id INT NOT NULL, ALGORITHM=INPLACE, LOCK=NONE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cms_menu_item ADD menu VARCHAR(32) NOT NULL, CHANGE menu_id menu_id INT DEFAULT NULL, ALGORITHM=INPLACE, LOCK=NONE');
    }
}
