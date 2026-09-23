<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Player-facing name for each ability (App\Entity\Ability::$label, shown by
 * the Activity's ability picker via GET /api/abilities) — backfills every
 * existing row from App\Enum\AbilityType::label()'s current text before
 * making the column NOT NULL, so nothing goes blank for existing seeded
 * abilities until an admin deliberately renames one.
 */
final class Version20260923211050 extends AbstractMigration
{
    private const DEFAULT_LABELS = [
        'flip' => 'Переворот',
        'unblockable_damage' => 'Неблокируемый урон',
        'reroll' => 'Переброс',
        'damage_mirror' => 'Зеркало урона',
        'destroy' => 'Уничтожение',
        'double' => 'Удвоение',
    ];

    public function getDescription(): string
    {
        return 'Add ability.label, backfilled from AbilityType::label()';
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement('ALTER TABLE ability ADD label VARCHAR(64) DEFAULT NULL');

        foreach (self::DEFAULT_LABELS as $type => $label) {
            $this->connection->executeStatement('UPDATE ability SET label = ? WHERE type = ?', [$label, $type]);
        }

        $this->connection->executeStatement('UPDATE ability SET label = type WHERE label IS NULL');
        $this->connection->executeStatement('ALTER TABLE ability ALTER COLUMN label SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement('ALTER TABLE ability DROP label');
    }
}
