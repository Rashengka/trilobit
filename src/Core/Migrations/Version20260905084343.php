<?php

declare(strict_types=1);

namespace Trilobit\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives the register of public addresses and the media library the tenant they
 * belong to, and moves the unique addresses onto it.
 *
 * The two indexes over core_content_path are the point of the change. An
 * address used to be unique across the installation, which said that two
 * businesses could not both have a page at /kontakt; it is now unique within a
 * tenant and a language, which is what that sentence should have said all
 * along. The language is in the index although nothing reads it yet, because
 * an index is migrated once or twice depending only on whether the column was
 * there the first time.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff`, restricted to
 * this module's own tables. It is committed as it came out - the description
 * above is added by hand, the statements below are not touched, because what
 * makes a migration trustworthy is that it is provably what Doctrine derived
 * from the mapping rather than what somebody believed it should be.
 */
final class Version20260905084343 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puts the tenant on the address register and the media library.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_6B86DF12586E415C ON core_content_path');
        $this->addSql('DROP INDEX UNIQ_6B86DF12B548B0F ON core_content_path');
        $this->addSql('ALTER TABLE core_content_path ADD language VARCHAR(12) NOT NULL, ADD tenant_id INT NOT NULL');
        $this->addSql('ALTER TABLE core_content_path ADD CONSTRAINT FK_6B86DF129033212A FOREIGN KEY (tenant_id) REFERENCES core_tenant (id)');
        $this->addSql('CREATE INDEX IDX_6B86DF129033212A ON core_content_path (tenant_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_address ON core_content_path (tenant_id, language, path)');
        $this->addSql('CREATE UNIQUE INDEX uniq_canonical ON core_content_path (tenant_id, language, canonical_of)');
        $this->addSql('ALTER TABLE core_media_file ADD tenant_id INT NOT NULL');
        $this->addSql('ALTER TABLE core_media_file ADD CONSTRAINT FK_73946D229033212A FOREIGN KEY (tenant_id) REFERENCES core_tenant (id)');
        $this->addSql('CREATE INDEX IDX_73946D229033212A ON core_media_file (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE core_content_path DROP FOREIGN KEY FK_6B86DF129033212A');
        $this->addSql('DROP INDEX IDX_6B86DF129033212A ON core_content_path');
        $this->addSql('DROP INDEX uniq_address ON core_content_path');
        $this->addSql('DROP INDEX uniq_canonical ON core_content_path');
        $this->addSql('ALTER TABLE core_content_path DROP language, DROP tenant_id');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6B86DF12586E415C ON core_content_path (canonical_of)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6B86DF12B548B0F ON core_content_path (path)');
        $this->addSql('ALTER TABLE core_media_file DROP FOREIGN KEY FK_73946D229033212A');
        $this->addSql('DROP INDEX IDX_73946D229033212A ON core_media_file');
        $this->addSql('ALTER TABLE core_media_file DROP tenant_id');
    }
}
