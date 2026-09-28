<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Ability::$actionCost, the admin-editable action-point cost that
 * AbilityRepository::costFor() now reads at battle-resolution time instead
 * of the old hardcoded AbilityType::fixedCost() (which remains only as the
 * fallback for code paths with no repository at hand, e.g. unit tests).
 *
 * Backfills every existing ability row with the value fixedCost() already
 * returned for it, so admin-visible costs — and actual battle behavior —
 * stay identical until an admin deliberately changes one. flip/
 * unblockable_damage stay NULL ("variable" — spend however many action
 * points were rolled), matching fixedCost()'s existing null case for them.
 *
 * (The migrations:diff run that produced this file also picked up
 * unrelated pre-existing drift on bit/equipment multiplier columns —
 * dropped here; not this migration's concern.)
 */
final class Version20260928134818 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Ability::actionCost, backfilled to match AbilityType::fixedCost()';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ability ADD action_cost INT DEFAULT NULL');
        $this->addSql("UPDATE ability SET action_cost = 1 WHERE type = 'reroll'");
        $this->addSql("UPDATE ability SET action_cost = 2 WHERE type IN ('damage_mirror', 'destroy', 'double')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ability DROP action_cost');
    }
}
