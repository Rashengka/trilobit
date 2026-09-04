<?php

declare(strict_types=1);

namespace Trilobit\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the rest of what Core owns: roles and the accounts holding them,
 * settings, and the media library.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff`, restricted to
 * this module's own tables. It is committed as it came out - the description
 * above is added by hand, the statements below are not touched, because what
 * makes a migration trustworthy is that it is provably what Doctrine derived
 * from the mapping rather than what somebody believed it should be.
 */
final class Version20260904185612 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds roles, settings and the media library to Core.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE core_media_file (id INT AUTO_INCREMENT NOT NULL, path VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL, mime VARCHAR(127) NOT NULL, size_bytes INT NOT NULL, created_at DATETIME NOT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, alt VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_73946D22B548B0F (path), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE core_role (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, permissions JSON NOT NULL, UNIQUE INDEX UNIQ_658C495F77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE core_setting (id INT AUTO_INCREMENT NOT NULL, `key` VARCHAR(191) NOT NULL, value JSON NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_8D630DAB4E645A7E (`key`), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE core_user_role (user_id INT NOT NULL, role_id INT NOT NULL, INDEX IDX_6288F687A76ED395 (user_id), INDEX IDX_6288F687D60322AC (role_id), PRIMARY KEY (user_id, role_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE core_user_role ADD CONSTRAINT FK_6288F687A76ED395 FOREIGN KEY (user_id) REFERENCES core_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE core_user_role ADD CONSTRAINT FK_6288F687D60322AC FOREIGN KEY (role_id) REFERENCES core_role (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE core_user_role DROP FOREIGN KEY FK_6288F687A76ED395');
        $this->addSql('ALTER TABLE core_user_role DROP FOREIGN KEY FK_6288F687D60322AC');
        $this->addSql('DROP TABLE core_media_file');
        $this->addSql('DROP TABLE core_role');
        $this->addSql('DROP TABLE core_setting');
        $this->addSql('DROP TABLE core_user_role');
    }
}
