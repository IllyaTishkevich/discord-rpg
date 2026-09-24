<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260924093502 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CharacterClass.frameName/frameSize/frameUpdatedAt — an optional decorative frame overlaid on a character\'s avatar.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_class ADD frame_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE character_class ADD frame_size INT DEFAULT NULL');
        $this->addSql('ALTER TABLE character_class ADD frame_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_class DROP frame_name');
        $this->addSql('ALTER TABLE character_class DROP frame_size');
        $this->addSql('ALTER TABLE character_class DROP frame_updated_at');
    }
}
