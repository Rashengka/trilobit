<?php

declare(strict_types=1);

namespace Trilobit\Shop\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the table binding a product to the pictures of Core's media library
 * that it shows (.ai/plans/30-obchod-katalog-t09.md, Q4).
 *
 * None of the three foreign keys cascades: the picture is Core's and outlives
 * the product - deleting files waits for plan 16 - and the bindings of a
 * product are taken away by whoever deletes it, where Doctrine sees it.
 *
 * **The foreign keys are added by an online copy rather than in place, and
 * none takes a lock.** Every ALTER TABLE here says how it is to run; the table
 * is InnoDB, the server's default engine. Measured on 2026-09-14 against the
 * server in compose.yaml (MariaDB 11.8.9), on a table of exactly this shape:
 *
 * - each of the three foreign keys, ALGORITHM=INPLACE, LOCK=NONE: refused,
 *   "ERROR 1846: Adding foreign keys needs foreign_key_checks=OFF" - and
 *   switching the checks off is not this migration's to decide (the reasoning
 *   is on Trilobit\Core\Migrations\Version20260913063908);
 * - each of them, ALGORITHM=COPY, LOCK=NONE: accepted;
 * - taking one back, ALGORITHM=INPLACE, LOCK=NONE: accepted.
 *
 * So they run as ALGORITHM=COPY, LOCK=NONE, the online copy, over a table
 * created by the statement before them and therefore empty. LOCK=NONE keeps
 * the guarantee: a server that could not copy without a lock refuses rather
 * than taking one.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff
 * --namespace='Trilobit\Shop\Migrations'`. It is committed as it came out -
 * the description, this docblock and the clause on each ALTER TABLE are added
 * by hand; the statements and their order are not touched.
 */
final class Version20260914071143 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the table binding a product to the pictures it shows.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE shop_product_image (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, tenant_id INT NOT NULL, product_id INT NOT NULL, file_id INT NOT NULL, INDEX IDX_7A7DE80C9033212A (tenant_id), INDEX IDX_7A7DE80C4584665A (product_id), INDEX IDX_7A7DE80C93CB796C (file_id), UNIQUE INDEX uniq_product_picture (product_id, file_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE shop_product_image ADD CONSTRAINT FK_7A7DE80C9033212A FOREIGN KEY (tenant_id) REFERENCES core_tenant (id), ALGORITHM=COPY, LOCK=NONE');
        $this->addSql('ALTER TABLE shop_product_image ADD CONSTRAINT FK_7A7DE80C4584665A FOREIGN KEY (product_id) REFERENCES shop_product (id), ALGORITHM=COPY, LOCK=NONE');
        $this->addSql('ALTER TABLE shop_product_image ADD CONSTRAINT FK_7A7DE80C93CB796C FOREIGN KEY (file_id) REFERENCES core_media_file (id), ALGORITHM=COPY, LOCK=NONE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE shop_product_image DROP FOREIGN KEY FK_7A7DE80C9033212A, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE shop_product_image DROP FOREIGN KEY FK_7A7DE80C4584665A, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE shop_product_image DROP FOREIGN KEY FK_7A7DE80C93CB796C, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('DROP TABLE shop_product_image');
    }
}
