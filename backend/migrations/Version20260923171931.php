<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * New combat ability: Double (App\Enum\AbilityType::Double) — spends 2
 * action points to permanently double the multiplier of one of the
 * caster's OWN not-yet-activated bits. Same seeding pattern as
 * Version20260923171529 (Destroy): insert the catalog row, grant it to
 * every *existing* class so today's "everything available to everyone"
 * default holds; new classes get it automatically via
 * SeedCharacterClassesCommand, which grants every Ability row it finds.
 */
final class Version20260923171931 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the Double ability and grant it to every existing class';
    }

    public function up(Schema $schema): void
    {
        $abilityId = (int) $this->connection->fetchOne(
            'INSERT INTO ability (type) VALUES (?) RETURNING id',
            ['double'],
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
        $this->connection->executeStatement("DELETE FROM ability WHERE type = 'double'");
    }
}
