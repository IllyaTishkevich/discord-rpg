<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cleans up character_bit duplicates left over from Version20260922230956
 * (the "Bit is a standalone definition" migration). That migration
 * preserved *every* bit a character used to own — via a `character_id`
 * column that no longer exists — as a "purchased" bit, since at the time
 * there was no way to tell an old per-character starter-bit *copy* (made
 * back when character creation still cloned the class's bits instead of
 * reading them live via Character::getAllBits()) apart from a genuine
 * equipment purchase using only the data on hand.
 *
 * The result: every character that existed before that migration now has
 * N extra phantom bits in character_bit, where N is exactly its class's
 * starter bit count — Character::getAllBits() (class bits + purchased
 * bits) double-counts them, e.g. a 3-bit warrior throwing 6 bits in
 * combat. Confirmed against real data: for every affected character,
 * `count(character_bit) - count(real bit-type equipment purchases)`
 * equals exactly their class's starter bit count.
 *
 * Fix: for each character, remove up to (character_bit count − real
 * purchase count) entries whose face signature (faceA/faceB/advantageA/
 * advantageB) matches one of the class's own starter bits — capped to the
 * class's own multiplicity per signature, so a genuine purchase that
 * happens to share a signature with a starter bit is never removed beyond
 * what the class itself accounts for (ties broken by removing the
 * lowest bit id first, since the old per-character copy was always
 * created before any later real purchase). This can't be made
 * mathematically perfect — the previous migration already discarded the
 * provenance needed to distinguish them with certainty — but it matches
 * every real row inspected during development exactly.
 */
final class Version20260923150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove leftover starter-bit duplicates from character_bit (see docblock)';
    }

    public function up(Schema $schema): void
    {
        $characters = $this->connection->fetchAllAssociative('SELECT id, character_class_id FROM "character"');

        foreach ($characters as $character) {
            $characterId = (int) $character['id'];
            $classId = (int) $character['character_class_id'];

            $realPurchaseCount = (int) $this->connection->fetchOne(
                'SELECT COUNT(*)
                 FROM character_equipment ce
                 JOIN equipment e ON e.id = ce.equipment_id
                 WHERE ce.character_id = ? AND e.effect_type = \'bit\'',
                [$characterId],
            );

            $currentBits = $this->connection->fetchAllAssociative(
                'SELECT b.id, b.face_a, b.face_b, b.advantage_a, b.advantage_b
                 FROM character_bit cb
                 JOIN "bit" b ON b.id = cb.bit_id
                 WHERE cb.character_id = ?
                 ORDER BY b.id ASC',
                [$characterId],
            );

            $excess = \count($currentBits) - $realPurchaseCount;
            if ($excess <= 0) {
                continue;
            }

            $starterBits = $this->connection->fetchAllAssociative(
                'SELECT b.face_a, b.face_b, b.advantage_a, b.advantage_b
                 FROM character_class_bit ccb
                 JOIN "bit" b ON b.id = ccb.bit_id
                 WHERE ccb.character_class_id = ?',
                [$classId],
            );

            $starterSignatureCounts = [];
            foreach ($starterBits as $bit) {
                $key = self::signature($bit);
                $starterSignatureCounts[$key] = ($starterSignatureCounts[$key] ?? 0) + 1;
            }

            $remaining = $excess;
            foreach ($currentBits as $bit) {
                if ($remaining <= 0) {
                    break;
                }

                $key = self::signature($bit);
                if (($starterSignatureCounts[$key] ?? 0) <= 0) {
                    continue;
                }

                $this->connection->executeStatement(
                    'DELETE FROM character_bit WHERE character_id = ? AND bit_id = ?',
                    [$characterId, $bit['id']],
                );

                $stillReferenced = (int) $this->connection->fetchOne(
                    'SELECT
                        (SELECT COUNT(*) FROM character_bit WHERE bit_id = ?)
                        + (SELECT COUNT(*) FROM character_class_bit WHERE bit_id = ?)',
                    [$bit['id'], $bit['id']],
                );
                if (0 === $stillReferenced) {
                    $this->connection->executeStatement('DELETE FROM "bit" WHERE id = ?', [$bit['id']]);
                }

                --$starterSignatureCounts[$key];
                --$remaining;
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Not reversible — we don't retain which specific rows this
        // removed. This is a data cleanup for a bug, not a schema change;
        // restore from a backup taken before running it if needed.
    }

    /**
     * @param array{face_a: string, face_b: string, advantage_a: mixed, advantage_b: mixed} $bit
     */
    private static function signature(array $bit): string
    {
        return \sprintf('%s|%s|%s|%s', $bit['face_a'], $bit['face_b'], $bit['advantage_a'], $bit['advantage_b']);
    }
}
