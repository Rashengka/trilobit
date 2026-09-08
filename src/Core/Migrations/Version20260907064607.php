<?php

declare(strict_types=1);

namespace Trilobit\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Says which accounts administer the installation itself rather than one of the
 * businesses inside it.
 *
 * It is a column on the account and not a role, because a role is held in a
 * business - see Trilobit\Core\Domain\Tenancy\Membership - and an account like
 * this is in none. The two are different scopes and not different levels: an
 * account above the businesses has no rights inside one at all.
 *
 * The column takes no default and every row that already exists gets 0, which
 * is the right thing to say about them: an installation upgraded to this build
 * has nobody administering it yet, and `bin/trilobit app:account` without
 * --tenant is how the first one is made.
 *
 * Generated from the mapping by `bin/trilobit migrations:diff`. It is committed
 * as it came out - the description above is added by hand, the statements below
 * are not touched, because what makes a migration trustworthy is that it is
 * provably what Doctrine derived from the mapping rather than what somebody
 * believed it should be.
 */
final class Version20260907064607 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Says which accounts administer the installation rather than a business inside it.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE core_user ADD landlord TINYINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE core_user DROP landlord');
    }
}
