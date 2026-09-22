<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260922232256 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BattleRound.exchanges (combat v2 step-by-step log, empty for pre-existing/PvP rounds)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE battle_round ADD exchanges JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE battle_round ALTER exchanges DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE battle_round DROP exchanges');
    }
}
