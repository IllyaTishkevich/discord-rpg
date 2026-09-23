<?php

namespace App\Service;

use App\Entity\Bit;
use App\Entity\Character;
use App\Entity\CharacterInventoryItem;
use App\Entity\Item;
use App\Entity\Monster;
use App\Enum\ItemEffectType;
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

            $dropped[] = $this->grantItem($character, $drop['item']);
        }

        if ([] !== $dropped) {
            $this->entityManager->flush();
        }

        return $dropped;
    }

    private function grantItem(Character $character, Item $item): Item
    {
        $inventoryItem = new CharacterInventoryItem($character, $item);

        if (ItemEffectType::AddBit === $item->getEffectType()) {
            $bit = new Bit(
                $item->getBitFaceA(),
                $item->getBitFaceB(),
                $item->hasBitAdvantageA(),
                $item->hasBitAdvantageB(),
                $item->getBitMultiplierA(),
                $item->getBitMultiplierB(),
            );
            $this->entityManager->persist($bit);
            $inventoryItem->setGrantedBit($bit);
        }

        $character->addInventoryItem($inventoryItem);
        $this->entityManager->persist($inventoryItem);

        return $item;
    }
}
