<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * New combat ability: Destroy (App\Enum\AbilityType::Destroy) — spends 2
 * action points to permanently remove one of the opponent's not-yet-activated
 * bits from the round. Seeds its catalog row and, same as
 * Version20260923113632 did for the original 4, grants it to every
 * *existing* class so today's "everything available to everyone" default
 * holds — new classes get it automatically via SeedCharacterClassesCommand,
 * which grants every Ability row it finds.
 *
 * Uses executeStatement() rather than addSql() for the same reason as
 * Version20260923113632: addSql() only queues statements to run after
 * up()/down() returns, too late to read the id it just inserted.
 */
final class Version20260923171529 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the Destroy ability and grant it to every existing class';
    }

    public function up(Schema $schema): void
    {
        $abilityId = (int) $this->connection->fetchOne(
            'INSERT INTO ability (type) VALUES (?) RETURNING id',
            ['destroy'],
        );

        $classIds = array_column($this->connection->fetchAllAssociative('SELECT id FROM character_class'), 'id');
        foreach ($classIds as $classId) {
            $this->connection->insert('character_class_ability', [
                'character_class_id' => (int) $classId,
                'ability_id' => $abilityId,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement("DELETE FROM ability WHERE type = 'destroy'");
    }
}
