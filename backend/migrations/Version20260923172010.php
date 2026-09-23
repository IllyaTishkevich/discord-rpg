<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin-facing "what does this ability do" text (App\Entity\Ability::$description)
 * — nullable, not read by any battle logic, purely documentation for the
 * admin panel (App\Controller\Admin\AbilityCrudController).
 */
final class Version20260923172010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a nullable description column to ability';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ability ADD description TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ability DROP description');
    }
}
