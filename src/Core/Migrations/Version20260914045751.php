<?php

declare(strict_types=1);

namespace Trilobit\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes the table the links a password is set with are kept in: a hash of the
 * token, the account it sets the password of, its week, and when it was used
 * or replaced by a newer one. See Trilobit\Core\Domain\User\PasswordLink
 * (.ai/plans/31-sprava-uzivatelu.md, O1).
 *
 * **The foreign key is copied rather than added in place, and without a
 * lock.** The table is InnoDB - the server's default engine, which the CREATE
 * TABLE above gets - and every ALTER TABLE here says how it is to run, so that
 * the server cannot choose a locking way on its own. MariaDB 11.8.9 refuses
 * ALGORITHM=INPLACE for adding a foreign key, measured on 2026-09-14 against
 * the server in compose.yaml on a table of this shape, with foreign_key_checks
 * on: "ERROR 1846: ALGORITHM=INPLACE is not supported. Reason: Adding foreign
 * keys needs foreign_key_checks=OFF. Try ALGORITHM=COPY"; ALGORITHM=COPY,
 * LOCK=NONE was accepted on the same table, and dropping the key again was
 * accepted INPLACE. Switching the checks off is not this migration's to
 * decide (the reasoning is on Version20260913063908). The table is new and
 * empty, so the copy costs nothing; LOCK=NONE keeps the guarantee that a
 * server which could not copy without a lock refuses rather than takes one.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff`. It is committed
 * as it came out - the description above and the two clauses on each ALTER
 * TABLE are added by hand, the statements and their order are not touched.
 */
final class Version20260914045751 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Makes the table the links a password is set with are kept in, as hashes.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE core_password_link (id INT AUTO_INCREMENT NOT NULL, used_at DATETIME DEFAULT NULL, superseded_at DATETIME DEFAULT NULL, token_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, account_id INT NOT NULL, UNIQUE INDEX UNIQ_2C5C74E5B3BC57DA (token_hash), INDEX IDX_2C5C74E59B6B5FBA (account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE core_password_link ADD CONSTRAINT FK_2C5C74E59B6B5FBA FOREIGN KEY (account_id) REFERENCES core_user (id), ALGORITHM=COPY, LOCK=NONE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE core_password_link DROP FOREIGN KEY FK_2C5C74E59B6B5FBA, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('DROP TABLE core_password_link');
    }
}
