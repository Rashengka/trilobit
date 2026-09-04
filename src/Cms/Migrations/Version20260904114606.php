<?php

declare(strict_types=1);

namespace Trilobit\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The first migration of a module: one table, so that switching the module off
 * and on again is something a test can watch happen.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff --namespace`,
 * restricted to this module's own tables. It is committed as it came out - the
 * description above is added by hand, the statements below are not touched.
 *
 * It goes away with the marker entity it creates, once the module has an
 * entity of its own.
 */
final class Version20260904114606 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the tables the Cms module owns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cms_marker (id INT AUTO_INCREMENT NOT NULL, installed_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cms_marker');
    }
}
