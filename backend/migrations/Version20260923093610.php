<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260923093610 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Battle.pendingExchangeState for the interactive step-by-step combat flow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE battle ADD pending_exchange_state JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE battle DROP pending_exchange_state');
    }
}
