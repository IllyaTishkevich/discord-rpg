<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260923163024 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a per-face damage/blocking/action-point multiplier to Bit and equipment-granted bits, defaulting to 1 (unchanged behavior) for every existing row';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "bit" ADD multiplier_a INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE "bit" ADD multiplier_b INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE equipment ADD bit_multiplier_a INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE equipment ADD bit_multiplier_b INT NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bit DROP multiplier_a');
        $this->addSql('ALTER TABLE bit DROP multiplier_b');
        $this->addSql('ALTER TABLE equipment DROP bit_multiplier_a');
        $this->addSql('ALTER TABLE equipment DROP bit_multiplier_b');
    }
}
