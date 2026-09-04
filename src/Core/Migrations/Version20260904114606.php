<?php

declare(strict_types=1);

namespace Trilobit\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The first migration of the always-enabled module: the people who can sign in.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff`, restricted to
 * this module's own tables. It is committed as it came out - the description
 * above is added by hand, the statements below are not touched, because what
 * makes a migration trustworthy is that it is provably what Doctrine derived
 * from the mapping rather than what somebody believed it should be.
 */
final class Version20260904114606 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the tables Core owns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE core_user (id INT AUTO_INCREMENT NOT NULL, active TINYINT NOT NULL, last_login_at DATETIME DEFAULT NULL, email VARCHAR(255) NOT NULL, password_hash VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_BF76157CE7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core_user');
    }
}
