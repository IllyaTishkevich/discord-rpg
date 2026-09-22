<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replaces CharacterClass.starterBits (a JSON blob) with a proper
 * many-to-many to reusable Bit template rows (Bit.character becomes
 * nullable — a template bit has no owning character). Existing classes'
 * JSON definitions are converted into real Bit rows and linked via the new
 * join table before the column is dropped.
 *
 * Uses $this->connection->executeStatement() throughout instead of
 * addSql() — addSql() only *queues* statements to run after up()/down()
 * returns, which would run them in the wrong order relative to the PHP-level
 * backfill logic below that needs the schema changes to have already
 * happened.
 */
final class Version20260922224450 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace CharacterClass.starterBits JSON with a Bit<->CharacterClass many-to-many of template bits';
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement('CREATE TABLE character_class_bit (bit_id INT NOT NULL, character_class_id INT NOT NULL, PRIMARY KEY(bit_id, character_class_id))');
        $this->connection->executeStatement('CREATE INDEX IDX_A69C79501D813127 ON character_class_bit (bit_id)');
        $this->connection->executeStatement('CREATE INDEX IDX_A69C7950B201E281 ON character_class_bit (character_class_id)');
        $this->connection->executeStatement('ALTER TABLE character_class_bit ADD CONSTRAINT FK_A69C79501D813127 FOREIGN KEY (bit_id) REFERENCES bit (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->connection->executeStatement('ALTER TABLE character_class_bit ADD CONSTRAINT FK_A69C7950B201E281 FOREIGN KEY (character_class_id) REFERENCES character_class (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->connection->executeStatement('ALTER TABLE "bit" ALTER character_id DROP NOT NULL');

        // Backfill: turn each class's starter_bits JSON blob into real
        // template Bit rows (character_id NULL) linked via the join table.
        $classes = $this->connection->fetchAllAssociative('SELECT id, starter_bits FROM character_class');
        foreach ($classes as $class) {
            $definitions = json_decode($class['starter_bits'], true) ?? [];
            foreach ($definitions as $definition) {
                $bitId = (int) $this->connection->fetchOne(
                    'INSERT INTO "bit" (face_a, face_b, advantage_a, advantage_b, character_id) VALUES (?, ?, ?, ?, NULL) RETURNING id',
                    [
                        $definition['faceA'],
                        $definition['faceB'],
                        (bool) ($definition['advantageA'] ?? false),
                        (bool) ($definition['advantageB'] ?? false),
                    ],
                    [ParameterType::STRING, ParameterType::STRING, ParameterType::BOOLEAN, ParameterType::BOOLEAN],
                );
                $this->connection->insert('character_class_bit', [
                    'bit_id' => $bitId,
                    'character_class_id' => (int) $class['id'],
                ]);
            }
        }

        $this->connection->executeStatement('ALTER TABLE character_class DROP starter_bits');
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement('ALTER TABLE character_class ADD starter_bits JSON DEFAULT \'[]\' NOT NULL');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ccb.character_class_id, b.face_a, b.face_b, b.advantage_a, b.advantage_b
             FROM character_class_bit ccb
             JOIN "bit" b ON b.id = ccb.bit_id',
        );
        $byClass = [];
        foreach ($rows as $row) {
            $byClass[$row['character_class_id']][] = [
                'faceA' => $row['face_a'],
                'faceB' => $row['face_b'],
                'advantageA' => (bool) $row['advantage_a'],
                'advantageB' => (bool) $row['advantage_b'],
            ];
        }
        foreach ($byClass as $classId => $definitions) {
            $this->connection->update('character_class', ['starter_bits' => json_encode($definitions)], ['id' => $classId]);
        }

        $this->connection->executeStatement('DELETE FROM "bit" WHERE character_id IS NULL');
        $this->connection->executeStatement('ALTER TABLE character_class_bit DROP CONSTRAINT FK_A69C79501D813127');
        $this->connection->executeStatement('ALTER TABLE character_class_bit DROP CONSTRAINT FK_A69C7950B201E281');
        $this->connection->executeStatement('DROP TABLE character_class_bit');
        $this->connection->executeStatement('ALTER TABLE bit ALTER character_id SET NOT NULL');
    }
}
