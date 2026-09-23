<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260923121941 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link Battle to the Monster catalog row its PvE/event opponent was drawn from';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE battle ADD opponent_monster_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              battle
            ADD
              CONSTRAINT FK_139917345135F61B FOREIGN KEY (opponent_monster_id) REFERENCES monster (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql('CREATE INDEX IDX_139917345135F61B ON battle (opponent_monster_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE battle DROP CONSTRAINT FK_139917345135F61B');
        $this->addSql('DROP INDEX IDX_139917345135F61B');
        $this->addSql('ALTER TABLE battle DROP opponent_monster_id');
    }
}
