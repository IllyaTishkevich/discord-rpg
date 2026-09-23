<?php

namespace App\Service;

use App\Entity\Character;
use App\Entity\Item;
use App\Entity\Monster;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rolls a defeated Monster's drop table (Monster::getDrops() — up to 5
 * independently-chanced slots) into the winning Character's inventory.
 * Called from BattleService::resolveOutcome() right after XP/coins are
 * granted for a PvE win.
 */
class LootService
{
    private \Closure $rollChance;

    /**
     * @param ?\Closure $rollChance (int $chancePercent) => bool — injectable
     *                              for deterministic tests, same pattern as
     *                              ExchangeResolver's $randomBool
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InventoryService $inventoryService,
        ?\Closure $rollChance = null,
    ) {
        $this->rollChance = $rollChance ?? static fn (int $chancePercent): bool => random_int(1, 100) <= $chancePercent;
    }

    /**
     * $monster is null for event battles and the pre-catalog fallback
     * opponent (neither ever calls Battle::setOpponentMonster() — see
     * BattleService::startEventBattle()/startPveBattle()), so this
     * naturally scopes loot to real PvE-catalog fights only.
     *
     * @return Item[] items actually granted — a roll that succeeds once the
     *                 inventory is already full (possibly filled by an
     *                 earlier drop in this same call) is skipped, not queued
     */
    public function rollDrops(Character $character, ?Monster $monster): array
    {
        if (null === $monster) {
            return [];
        }

        $dropped = [];
        foreach ($monster->getDrops() as $drop) {
            if (\count($character->getInventoryItems()) >= $character->getInventoryCapacity()) {
                break;
            }
            if (!($this->rollChance)($drop['chance'])) {
                continue;
            }

            $this->inventoryService->grantItem($character, $drop['item']);
            $dropped[] = $drop['item'];
        }

        if ([] !== $dropped) {
            $this->entityManager->flush();
        }

        return $dropped;
    }
}
