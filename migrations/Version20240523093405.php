<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add Zitadel user ID and sync error fields to the user_alumni table.
 */
final class Version20240523093405 extends AbstractMigration
{
    /**
     * Returns a description of the migration.
     *
     * @return string Description of the migration.
     */
    public function getDescription(): string
    {
        return 'Add Zitadel user ID and sync error fields to user_alumni table.';
    }

    /**
     * Applies the migration by adding new columns to the user_alumni table.
     *
     * @param Schema $schema The schema to apply the migration to.
     *
     * @return void
     */
    public function up(Schema $schema): void
    {
        $this->addZitadelColumns($schema);
    }

    /**
     * Reverts the migration by removing the added columns from the user_alumni table.
     *
     * @param Schema $schema The schema to revert the migration from.
     *
     * @return void
     */
    public function down(Schema $schema): void
    {
        $this->removeZitadelColumns($schema);
    }

    /**
     * Adds Zitadel user ID and sync error columns to the user_alumni table.
     *
     * @param Schema $schema The schema to modify.
     *
     * @return void
     */
    private function addZitadelColumns(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_alumni ADD zitadel_user_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE user_alumni ADD zitadel_sync_error VARCHAR(255) DEFAULT NULL');
    }

    /**
     * Removes Zitadel user ID and sync error columns from the user_alumni table.
     *
     * @param Schema $schema The schema to modify.
     *
     * @return void
     */
    private function removeZitadelColumns(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_alumni DROP zitadel_user_id');
        $this->addSql('ALTER TABLE user_alumni DROP zitadel_sync_error');
    }
}
