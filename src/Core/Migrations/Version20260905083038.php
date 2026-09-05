<?php

declare(strict_types=1);

namespace Trilobit\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the tenant, the hosts it answers at, and the way a person belongs to
 * one; see Trilobit\Core\Domain\Tenancy\Tenant.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff`, restricted to
 * this module's own tables. It is committed as it came out - the description
 * above is added by hand, the statements below are not touched, because what
 * makes a migration trustworthy is that it is provably what Doctrine derived
 * from the mapping rather than what somebody believed it should be.
 *
 * It adds no column to a table that was already there. The tables that gain
 * the dimension itself are migrated separately, so that a review of this one
 * is a review of what a tenant is and a review of that one is a review of what
 * a unique index now means.
 */
final class Version20260905083038 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the tenant, its domains and its memberships to Core.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE core_domain (id INT AUTO_INCREMENT NOT NULL, host VARCHAR(255) NOT NULL, tenant_id INT NOT NULL, UNIQUE INDEX UNIQ_7F57D52CF2713FD (host), INDEX IDX_7F57D529033212A (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE core_tenant (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(191) NOT NULL, created_at DATETIME NOT NULL, language_strategy VARCHAR(16) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE core_tenant_membership (id INT AUTO_INCREMENT NOT NULL, tenant_id INT NOT NULL, user_id INT NOT NULL, role_id INT NOT NULL, INDEX IDX_79B68D119033212A (tenant_id), INDEX IDX_79B68D11A76ED395 (user_id), INDEX IDX_79B68D11D60322AC (role_id), UNIQUE INDEX uniq_membership (tenant_id, user_id, role_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE core_domain ADD CONSTRAINT FK_7F57D529033212A FOREIGN KEY (tenant_id) REFERENCES core_tenant (id)');
        $this->addSql('ALTER TABLE core_tenant_membership ADD CONSTRAINT FK_79B68D119033212A FOREIGN KEY (tenant_id) REFERENCES core_tenant (id)');
        $this->addSql('ALTER TABLE core_tenant_membership ADD CONSTRAINT FK_79B68D11A76ED395 FOREIGN KEY (user_id) REFERENCES core_user (id)');
        $this->addSql('ALTER TABLE core_tenant_membership ADD CONSTRAINT FK_79B68D11D60322AC FOREIGN KEY (role_id) REFERENCES core_role (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE core_domain DROP FOREIGN KEY FK_7F57D529033212A');
        $this->addSql('ALTER TABLE core_tenant_membership DROP FOREIGN KEY FK_79B68D119033212A');
        $this->addSql('ALTER TABLE core_tenant_membership DROP FOREIGN KEY FK_79B68D11A76ED395');
        $this->addSql('ALTER TABLE core_tenant_membership DROP FOREIGN KEY FK_79B68D11D60322AC');
        $this->addSql('DROP TABLE core_domain');
        $this->addSql('DROP TABLE core_tenant');
        $this->addSql('DROP TABLE core_tenant_membership');
    }
}
