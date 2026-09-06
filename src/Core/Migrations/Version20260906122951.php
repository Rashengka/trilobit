<?php

declare(strict_types=1);

namespace Trilobit\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives an account the choices its owner has made about the way the
 * application is drawn - the theme, the light or dark mode, and whatever is
 * added to Trilobit\Core\Preference\PreferenceCatalogue after them.
 *
 * One column for all of them rather than one each, so that the third and the
 * fourth are not two more of these files. The column takes null because it is
 * added to a table that may already have rows and there is nothing to backfill
 * it with: a row written before the column existed says null, which is exactly
 * "this person has chosen nothing".
 *
 * Generated from the mapping by `bin/trilobit migrations:diff`. It is committed
 * as it came out - the description above is added by hand, the statements below
 * are not touched, because what makes a migration trustworthy is that it is
 * provably what Doctrine derived from the mapping rather than what somebody
 * believed it should be.
 */
final class Version20260906122951 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puts what somebody prefers about the way the application looks on their account.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE core_user ADD preferences JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE core_user DROP preferences');
    }
}
