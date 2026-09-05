<?php

declare(strict_types=1);

namespace Trilobit\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the register the public address space is read out of; see
 * Trilobit\Core\Domain\Content\ContentPath.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff`, restricted to
 * this module's own tables. It is committed as it came out - the description
 * above is added by hand, the statements below are not touched, because what
 * makes a migration trustworthy is that it is provably what Doctrine derived
 * from the mapping rather than what somebody believed it should be.
 */
final class Version20260905050904 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the register of public addresses to Core.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE core_content_path (id INT AUTO_INCREMENT NOT NULL, canonical_of VARCHAR(191) DEFAULT NULL, path VARCHAR(255) NOT NULL, type VARCHAR(64) NOT NULL, content_id VARCHAR(64) NOT NULL, label VARCHAR(191) NOT NULL, moved_to_id INT DEFAULT NULL, parent_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_6B86DF12586E415C (canonical_of), UNIQUE INDEX UNIQ_6B86DF12B548B0F (path), INDEX IDX_6B86DF12BFAF4E02 (moved_to_id), INDEX IDX_6B86DF12727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE core_content_path ADD CONSTRAINT FK_6B86DF12BFAF4E02 FOREIGN KEY (moved_to_id) REFERENCES core_content_path (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE core_content_path ADD CONSTRAINT FK_6B86DF12727ACA70 FOREIGN KEY (parent_id) REFERENCES core_content_path (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE core_content_path DROP FOREIGN KEY FK_6B86DF12BFAF4E02');
        $this->addSql('ALTER TABLE core_content_path DROP FOREIGN KEY FK_6B86DF12727ACA70');
        $this->addSql('DROP TABLE core_content_path');
    }
}
