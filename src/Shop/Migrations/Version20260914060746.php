<?php

declare(strict_types=1);

namespace Trilobit\Shop\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the table of products and drops the marker the Shop module held
 * until it had a table of its own (.ai/plans/30-obchod-katalog-t09.md; the
 * drop was approved there on 2026-09-13). The migration that created the marker
 * stays: it is history an installation has already run.
 *
 * **The foreign key is added by an online copy rather than in place, and
 * neither takes a lock.** Every ALTER TABLE here says how it is to run; the
 * table is InnoDB, the server's default engine. Measured on 2026-09-14 against
 * the server in compose.yaml (MariaDB 11.8.9), on a table of exactly this
 * shape:
 *
 * - the foreign key, ALGORITHM=INPLACE, LOCK=NONE: refused, "ERROR 1846:
 *   Adding foreign keys needs foreign_key_checks=OFF" - and switching the
 *   checks off is not this migration's to decide (the reasoning is on
 *   Trilobit\Core\Migrations\Version20260913063908);
 * - the foreign key, ALGORITHM=COPY, LOCK=NONE: accepted;
 * - taking it back, ALGORITHM=INPLACE, LOCK=NONE: accepted.
 *
 * So it runs as ALGORITHM=COPY, LOCK=NONE, the online copy MariaDB has had
 * since 11.2, over a table created by the statement before it and therefore
 * empty. LOCK=NONE keeps the guarantee: a server that could not copy without a
 * lock refuses rather than taking one.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff
 * --namespace='Trilobit\Shop\Migrations'`. It is committed as it came out -
 * the description, this docblock and the clause on each ALTER TABLE are added
 * by hand; the statements and their order are not touched.
 */
final class Version20260914060746 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the table of products and drops the marker the module held until it had one.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE shop_product (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(16) NOT NULL, sku VARCHAR(64) DEFAULT NULL, perex LONGTEXT NOT NULL, description LONGTEXT NOT NULL, vat_rate INT NOT NULL, name VARCHAR(191) NOT NULL, updated_at DATETIME NOT NULL, price_amount BIGINT NOT NULL, price_currency VARCHAR(3) NOT NULL, tenant_id INT NOT NULL, INDEX IDX_D07944879033212A (tenant_id), UNIQUE INDEX uniq_product_sku (tenant_id, sku), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE shop_product ADD CONSTRAINT FK_D07944879033212A FOREIGN KEY (tenant_id) REFERENCES core_tenant (id), ALGORITHM=COPY, LOCK=NONE');
        $this->addSql('DROP TABLE shop_marker');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE shop_marker (id INT AUTO_INCREMENT NOT NULL, installed_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE shop_product DROP FOREIGN KEY FK_D07944879033212A, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('DROP TABLE shop_product');
    }
}
