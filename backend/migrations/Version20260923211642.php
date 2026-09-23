<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BattleRound::$droppedItems — a snapshot of whatever LootService::rollDrops()
 * granted the round a PvE monster died (name/iconName pairs; see that
 * entity's docblock for why this is a snapshot rather than a live relation).
 * Defaults existing rows to an empty array — none of them could have
 * dropped anything retroactively.
 */
final class Version20260923211642 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add battle_round.dropped_items';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE battle_round ADD dropped_items JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE battle_round ALTER COLUMN dropped_items DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE battle_round DROP dropped_items');
    }
}
