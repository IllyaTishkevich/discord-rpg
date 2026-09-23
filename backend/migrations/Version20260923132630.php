<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260923132630 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the whole-round PvP ability-choice columns — PvP now uses the same interactive exchange flow as PvE/event';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE battle DROP pending_character_action_targets');
        $this->addSql('ALTER TABLE battle DROP pending_opponent_action_targets');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE battle ADD pending_character_action_targets JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE battle ADD pending_opponent_action_targets JSON DEFAULT NULL');
    }
}
