<?php

declare(strict_types=1);

namespace Trilobit\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives the Cms module the two tables it is for: the pages somebody writes,
 * and the entries they are listed under.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff --namespace`,
 * restricted to this module's own tables. It is committed as it came out - the
 * description above is added by hand, the statements below are not touched,
 * because what makes a migration trustworthy is that it is provably what
 * Doctrine derived from the mapping rather than what somebody believed it
 * should be.
 *
 * The marker table goes in the same file, and deliberately so: it existed only
 * so that switching this module off and on again was something a test could
 * watch happen, and a module with tables of its own no longer needs a table
 * saying that it has some. Its own migration stays where it is, because an
 * installation that ran it has to be told the table is gone rather than have
 * the history rewritten under it.
 *
 * There is no column here saying where a page answers. That is a row in
 * core_content_path, the one table an address is unique in across every module
 * (.ai/plans/01e-routing-a-provazani-obsahu.md, R1 and R2); a slug beside it
 * would be a second answer to the same question, and the two would disagree
 * the first time one of them was written without the other.
 */
final class Version20260906070741 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the pages and the menu entries the Cms module owns, and drops the marker they replace.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cms_menu_item (id INT AUTO_INCREMENT NOT NULL, menu VARCHAR(32) NOT NULL, label VARCHAR(191) NOT NULL, target_type VARCHAR(16) NOT NULL, target VARCHAR(255) NOT NULL, position INT NOT NULL, visible TINYINT NOT NULL, tenant_id INT NOT NULL, page_id INT DEFAULT NULL, parent_id INT DEFAULT NULL, INDEX IDX_1432B53D9033212A (tenant_id), INDEX IDX_1432B53DC4663E4 (page_id), INDEX IDX_1432B53D727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE cms_page (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(16) NOT NULL, published_at DATETIME DEFAULT NULL, perex LONGTEXT NOT NULL, content LONGTEXT NOT NULL, seo_title VARCHAR(191) NOT NULL, seo_description VARCHAR(255) NOT NULL, title VARCHAR(191) NOT NULL, updated_at DATETIME NOT NULL, tenant_id INT NOT NULL, INDEX IDX_D39C1B5D9033212A (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE cms_menu_item ADD CONSTRAINT FK_1432B53D9033212A FOREIGN KEY (tenant_id) REFERENCES core_tenant (id)');
        $this->addSql('ALTER TABLE cms_menu_item ADD CONSTRAINT FK_1432B53DC4663E4 FOREIGN KEY (page_id) REFERENCES cms_page (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cms_menu_item ADD CONSTRAINT FK_1432B53D727ACA70 FOREIGN KEY (parent_id) REFERENCES cms_menu_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cms_page ADD CONSTRAINT FK_D39C1B5D9033212A FOREIGN KEY (tenant_id) REFERENCES core_tenant (id)');
        $this->addSql('DROP TABLE cms_marker');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cms_marker (id INT AUTO_INCREMENT NOT NULL, installed_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE cms_menu_item DROP FOREIGN KEY FK_1432B53D9033212A');
        $this->addSql('ALTER TABLE cms_menu_item DROP FOREIGN KEY FK_1432B53DC4663E4');
        $this->addSql('ALTER TABLE cms_menu_item DROP FOREIGN KEY FK_1432B53D727ACA70');
        $this->addSql('ALTER TABLE cms_page DROP FOREIGN KEY FK_D39C1B5D9033212A');
        $this->addSql('DROP TABLE cms_menu_item');
        $this->addSql('DROP TABLE cms_page');
    }
}
