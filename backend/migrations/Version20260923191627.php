<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two new equippable-item effects (App\Enum\ItemEffectType::IncreaseMaxHp/
 * IncreaseMaxEnergy) — a live bonus to Character::getEffectiveMaxHp()/
 * getEffectiveMaxEnergy() while the granting item stays equipped, same
 * pattern as IncreaseCapacity's existing bonus to getInventoryCapacity().
 */
final class Version20260923191627 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add max_hp_bonus/max_energy_bonus nullable columns to item';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item ADD max_hp_bonus INT DEFAULT NULL');
        $this->addSql('ALTER TABLE item ADD max_energy_bonus INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item DROP max_hp_bonus');
        $this->addSql('ALTER TABLE item DROP max_energy_bonus');
    }
}
