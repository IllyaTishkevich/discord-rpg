<?php

namespace App\Service;

use App\Entity\Bit;
use App\Entity\Character;
use App\Entity\CharacterInventoryItem;
use App\Entity\Item;
use App\Enum\ItemEffectType;
use App\Exception\InsufficientCoinsException;
use App\Exception\InventoryFullException;
use App\Exception\ItemNotOwnedException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The 3 actions available on an inventory cell (ArenaScreen-equivalent for
 * the Activity's Inventory screen): use, sell, discard — plus granting a new
 * item in the first place, either bought from the shop (purchaseItem(), see
 * ItemController) or looted (grantItem(), see LootService).
 */
class InventoryService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Creates the owned-item row for $item, including a concrete Bit if it's
     * an AddBit item (mirrors EquipmentService::purchase()'s Bit creation) —
     * the shared primitive behind both a shop purchase and a loot drop.
     * Doesn't flush or check capacity/cost — callers decide that (LootService
     * skips a drop that would overflow the inventory rather than erroring;
     * purchaseItem() below throws instead, since it's a deliberate paid
     * action).
     */
    public function grantItem(Character $character, Item $item): CharacterInventoryItem
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

        return $inventoryItem;
    }

    /**
     * Buys $item from the shop (ItemController) — spends coins equal to its
     * price (same field sellItem() refunds half of) and grants it via
     * grantItem() above.
     */
    public function purchaseItem(Character $character, Item $item): CharacterInventoryItem
    {
        if (\count($character->getInventoryItems()) >= $character->getInventoryCapacity()) {
            throw new InventoryFullException('Inventory is full.');
        }
        if (!$character->trySpendCoins($item->getPrice())) {
            throw new InsufficientCoinsException('Not enough coins to buy this item.');
        }

        $row = $this->grantItem($character, $item);
        $this->entityManager->flush();

        return $row;
    }

    /**
     * Equippable item (weapon/shield/armor/bag): equips it, auto-swapping
     * off whatever else of the same type was already equipped — no separate
     * unequip action exists. Consumable item (potion/scroll): applies its
     * one instant effect (heal/restore energy/grant XP) and destroys it.
     */
    public function useItem(Character $character, CharacterInventoryItem $row): void
    {
        $this->assertOwnership($character, $row);
        $item = $row->getItem();

        if ($item->getType()->isEquippable()) {
            foreach ($character->getInventoryItems() as $other) {
                if ($other !== $row && $other->isEquipped() && $other->getItem()->getType() === $item->getType()) {
                    $other->setEquipped(false);
                }
            }
            $row->setEquipped(true);
            $this->entityManager->flush();

            return;
        }

        match ($item->getEffectType()) {
            ItemEffectType::Heal => $character->setHp($character->getHp() + ($item->getHealAmount() ?? 0)),
            ItemEffectType::RestoreEnergy => $character->setEnergy($character->getEnergy() + ($item->getEnergyAmount() ?? 0)),
            ItemEffectType::GrantXp => $character->addXp($item->getXpAmount() ?? 0),
            // Unreachable for a consumable item — Item::validateEffectMatchesType()
            // only allows Heal/RestoreEnergy/GrantXp on Potion/Scroll.
            default => null,
        };

        $this->removeRow($character, $row);
    }

    /**
     * @return int coins refunded (half the item's price)
     */
    public function sellItem(Character $character, CharacterInventoryItem $row): int
    {
        $this->assertOwnership($character, $row);

        $refund = intdiv($row->getItem()->getPrice(), 2);
        $character->addCoins($refund);
        $this->removeRow($character, $row);

        return $refund;
    }

    public function discardItem(Character $character, CharacterInventoryItem $row): void
    {
        $this->assertOwnership($character, $row);
        $this->removeRow($character, $row);
    }

    private function removeRow(Character $character, CharacterInventoryItem $row): void
    {
        $character->removeInventoryItem($row);
        if (null !== $row->getGrantedBit()) {
            $this->entityManager->remove($row->getGrantedBit());
        }
        $this->entityManager->remove($row);
        $this->entityManager->flush();
    }

    private function assertOwnership(Character $character, CharacterInventoryItem $row): void
    {
        if ($row->getCharacter() !== $character) {
            throw new ItemNotOwnedException('This inventory item does not belong to you.');
        }
    }
}
