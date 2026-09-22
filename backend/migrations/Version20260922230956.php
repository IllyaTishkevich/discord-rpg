<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bit no longer references a Character at all (see Bit's docblock) —
 * ownership now goes the other way: CharacterClass::$starterBits (already
 * in place) and the new Character::$purchasedBits. Any bit currently owned
 * by a character (whether copied from its class at creation, now
 * redundant, or bought via equipment, which must be preserved) is carried
 * over into the new character_bit join table before the old column drops.
 */
final class Version20260922230956 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bit no longer references Character directly — add character_bit (purchased bits), drop bit.character_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE character_bit (character_id INT NOT NULL, bit_id INT NOT NULL, PRIMARY KEY(character_id, bit_id))');
        $this->addSql('CREATE INDEX IDX_1CEF78091136BE75 ON character_bit (character_id)');
        $this->addSql('CREATE INDEX IDX_1CEF78091D813127 ON character_bit (bit_id)');
        $this->addSql('ALTER TABLE character_bit ADD CONSTRAINT FK_1CEF78091136BE75 FOREIGN KEY (character_id) REFERENCES character (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE character_bit ADD CONSTRAINT FK_1CEF78091D813127 FOREIGN KEY (bit_id) REFERENCES bit (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Preserve every currently-owned bit (starter copies and equipment
        // purchases alike — indistinguishable at the data level) as a
        // "purchased" bit rather than losing them when character_id drops.
        $this->addSql('INSERT INTO character_bit (character_id, bit_id) SELECT character_id, id FROM "bit" WHERE character_id IS NOT NULL');

        $this->addSql('ALTER TABLE "bit" DROP CONSTRAINT fk_5745a3971136be75');
        $this->addSql('DROP INDEX idx_5745a3971136be75');
        $this->addSql('ALTER TABLE "bit" DROP character_id');

        // character_class_bit's owning side flipped from Bit to
        // CharacterClass (Bit no longer declares the relation at all) —
        // same columns, just Doctrine's canonical PK column order for the
        // new owning side. No data loss, purely a constraint redefinition.
        $this->addSql('ALTER TABLE character_class_bit DROP CONSTRAINT character_class_bit_pkey');
        $this->addSql('ALTER TABLE character_class_bit ADD PRIMARY KEY (character_class_id, bit_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bit ADD character_id INT DEFAULT NULL');

        // Best-effort: restores one owning character per bit (arbitrarily
        // the first found) — a bit linked to several characters via
        // character_bit (not possible via the pre-migration schema, but
        // possible if this join table was used post-migration) can't fully
        // round-trip back to the single-owner column.
        $this->addSql('UPDATE "bit" SET character_id = cb.character_id FROM (SELECT DISTINCT ON (bit_id) bit_id, character_id FROM character_bit ORDER BY bit_id, character_id) cb WHERE cb.bit_id = "bit".id');

        $this->addSql('ALTER TABLE bit ADD CONSTRAINT fk_5745a3971136be75 FOREIGN KEY (character_id) REFERENCES "character" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_5745a3971136be75 ON bit (character_id)');

        $this->addSql('ALTER TABLE character_bit DROP CONSTRAINT FK_1CEF78091136BE75');
        $this->addSql('ALTER TABLE character_bit DROP CONSTRAINT FK_1CEF78091D813127');
        $this->addSql('DROP TABLE character_bit');

        $this->addSql('ALTER TABLE character_class_bit DROP CONSTRAINT character_class_bit_pkey');
        $this->addSql('ALTER TABLE character_class_bit ADD PRIMARY KEY (bit_id, character_class_id)');
    }
}
