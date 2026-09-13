<?php

declare(strict_types=1);

namespace Trilobit\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes a menu for every name the arranged entries of a business use, and
 * points each entry at the menu of its own business under its own name - the
 * second of three steps (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3,
 * decided 2026-09-13).
 *
 * **This one is written by hand, and it is data, not structure.** The step
 * before it and the step after it are generated from the mapping; nothing in
 * a mapping describes which rows have to exist for a column to be required,
 * so no generator writes this. Without it the next step fails on the first
 * installation with an entry arranged - and a suite that migrates an empty
 * database would never see that, which is why
 * Trilobit\Tests\Integration\Cms\MenuEntriesFindTheirMenuThroughTheMigrationsTest
 * puts entries in the way the old schema held them first.
 *
 * A menu belongs to one business, so the pairing is by business and name:
 * two businesses each with a `main` get a menu each. A menu already there -
 * one somebody saved an arrangement for before this ran - is used rather than
 * made again, which is also what lets this be run twice.
 *
 * Taking it back gives every entry the name of its menu again. The menus are
 * left where they are: they are Core's rows, and the migration that made the
 * table takes them away with it.
 */
final class Version20260913105918 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Makes a menu for every name the arranged entries of a business use, and points each entry at it.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO core_menu (tenant_id, name)'
            . ' SELECT DISTINCT i.tenant_id, i.menu FROM cms_menu_item i'
            . ' WHERE NOT EXISTS (SELECT 1 FROM core_menu m WHERE m.tenant_id = i.tenant_id AND m.name = i.menu)',
        );
        $this->addSql(
            'UPDATE cms_menu_item i JOIN core_menu m ON m.tenant_id = i.tenant_id AND m.name = i.menu'
            . ' SET i.menu_id = m.id',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE cms_menu_item i JOIN core_menu m ON m.id = i.menu_id SET i.menu = m.name');
    }
}
