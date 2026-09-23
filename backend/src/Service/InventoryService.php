<?php

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterInventoryItem;
use App\Enum\ItemEffectType;
use App\Exception\ItemNotOwnedException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The 3 actions available on an inventory cell (ArenaScreen-equivalent for
 * the Activity's Inventory screen): use, sell, discard.
 */
class InventoryService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
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
