<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260923120106 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add 256x256 icon uploads to Bit (one per face), CharacterClass, Ability and Equipment';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ability ADD icon_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ability ADD icon_size INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ability ADD icon_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "bit" ADD icon_aname VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "bit" ADD icon_asize INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "bit" ADD icon_aupdated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "bit" ADD icon_bname VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "bit" ADD icon_bsize INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "bit" ADD icon_bupdated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE character_class ADD icon_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE character_class ADD icon_size INT DEFAULT NULL');
        $this->addSql('ALTER TABLE character_class ADD icon_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE equipment ADD icon_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE equipment ADD icon_size INT DEFAULT NULL');
        $this->addSql('ALTER TABLE equipment ADD icon_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bit DROP icon_aname');
        $this->addSql('ALTER TABLE bit DROP icon_asize');
        $this->addSql('ALTER TABLE bit DROP icon_aupdated_at');
        $this->addSql('ALTER TABLE bit DROP icon_bname');
        $this->addSql('ALTER TABLE bit DROP icon_bsize');
        $this->addSql('ALTER TABLE bit DROP icon_bupdated_at');
        $this->addSql('ALTER TABLE equipment DROP icon_name');
        $this->addSql('ALTER TABLE equipment DROP icon_size');
        $this->addSql('ALTER TABLE equipment DROP icon_updated_at');
        $this->addSql('ALTER TABLE ability DROP icon_name');
        $this->addSql('ALTER TABLE ability DROP icon_size');
        $this->addSql('ALTER TABLE ability DROP icon_updated_at');
        $this->addSql('ALTER TABLE character_class DROP icon_name');
        $this->addSql('ALTER TABLE character_class DROP icon_size');
        $this->addSql('ALTER TABLE character_class DROP icon_updated_at');
    }
}
