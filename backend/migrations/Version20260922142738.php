<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260922142738 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Existing rows get sensible defaults (pve / all sides "ready" —
        // that flag only matters for PvP's waiting-room flow), then the
        // defaults are dropped so the app's own code stays the single
        // source of truth for new rows.
        $this->addSql("ALTER TABLE battle ADD mode VARCHAR(255) NOT NULL DEFAULT 'pve'");
        $this->addSql('ALTER TABLE battle ADD character_ready BOOLEAN NOT NULL DEFAULT true');
        $this->addSql('ALTER TABLE battle ADD opponent_ready BOOLEAN NOT NULL DEFAULT true');
        $this->addSql('ALTER TABLE battle ADD opponent_accepted BOOLEAN NOT NULL DEFAULT true');
        $this->addSql('ALTER TABLE battle ADD round_deadline_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE battle ADD pending_character_action_targets JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE battle ADD pending_opponent_action_targets JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE battle ADD opponent_character_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE battle ADD CONSTRAINT FK_13991734A1CEB025 FOREIGN KEY (opponent_character_id) REFERENCES character (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_13991734A1CEB025 ON battle (opponent_character_id)');
        $this->addSql("UPDATE battle SET mode = 'event' WHERE event_id IS NOT NULL");
        $this->addSql('ALTER TABLE battle ALTER COLUMN mode DROP DEFAULT');
        $this->addSql('ALTER TABLE battle ALTER COLUMN character_ready DROP DEFAULT');
        $this->addSql('ALTER TABLE battle ALTER COLUMN opponent_ready DROP DEFAULT');
        $this->addSql('ALTER TABLE battle ALTER COLUMN opponent_accepted DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE battle DROP CONSTRAINT FK_13991734A1CEB025');
        $this->addSql('DROP INDEX IDX_13991734A1CEB025');
        $this->addSql('ALTER TABLE battle DROP mode');
        $this->addSql('ALTER TABLE battle DROP character_ready');
        $this->addSql('ALTER TABLE battle DROP opponent_ready');
        $this->addSql('ALTER TABLE battle DROP opponent_accepted');
        $this->addSql('ALTER TABLE battle DROP round_deadline_at');
        $this->addSql('ALTER TABLE battle DROP pending_character_action_targets');
        $this->addSql('ALTER TABLE battle DROP pending_opponent_action_targets');
        $this->addSql('ALTER TABLE battle DROP opponent_character_id');
    }
}
