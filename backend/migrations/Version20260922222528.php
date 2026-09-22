<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260922222528 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the "advantage" flag to bit faces (docs/COMBAT_V2_DESIGN.md)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "bit" ADD advantage_a BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE "bit" ADD advantage_b BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE equipment ADD bit_advantage_a BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE equipment ADD bit_advantage_b BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE "bit" ALTER advantage_a DROP DEFAULT');
        $this->addSql('ALTER TABLE "bit" ALTER advantage_b DROP DEFAULT');
        $this->addSql('ALTER TABLE equipment ALTER bit_advantage_a DROP DEFAULT');
        $this->addSql('ALTER TABLE equipment ALTER bit_advantage_b DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE equipment DROP bit_advantage_a');
        $this->addSql('ALTER TABLE equipment DROP bit_advantage_b');
        $this->addSql('ALTER TABLE bit DROP advantage_a');
        $this->addSql('ALTER TABLE bit DROP advantage_b');
    }
}
