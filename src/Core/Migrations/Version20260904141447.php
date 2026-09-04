<?php

declare(strict_types=1);

namespace Trilobit\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the audit trail: Trilobit\Core\Domain\Audit\AuditEntry, written to by
 * Trilobit\Core\Event\AuditListener in response to EntityChanged.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff`, restricted to
 * this module's own tables. It is committed as it came out - the description
 * above is added by hand, the statements below are not touched, because what
 * makes a migration trustworthy is that it is provably what Doctrine derived
 * from the mapping rather than what somebody believed it should be.
 */
final class Version20260904141447 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the audit trail Core owns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE core_audit_entry (id INT AUTO_INCREMENT NOT NULL, entity_type VARCHAR(255) NOT NULL, entity_id VARCHAR(255) NOT NULL, action VARCHAR(32) NOT NULL, occurred_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core_audit_entry');
    }
}
