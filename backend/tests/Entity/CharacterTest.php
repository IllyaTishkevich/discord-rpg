<?php

namespace App\Tests\Entity;

use App\Entity\Character;
use App\Entity\CharacterClass;
use App\Entity\CharacterInventoryItem;
use App\Entity\Item;
use App\Entity\User;
use App\Enum\ItemEffectType;
use App\Enum\ItemType;
use PHPUnit\Framework\TestCase;

class CharacterTest extends TestCase
{
    private function makeCharacter(int $baseHp = 30, int $baseEnergy = 10): Character
    {
        $user = new User('discord-id', 'Tester');
        $class = new CharacterClass('warrior', 'Воин', $baseHp, $baseEnergy);

        return new Character($user, $class);
    }

    private function equip(Character $character, Item $item): CharacterInventoryItem
    {
        $row = new CharacterInventoryItem($character, $item);
        $row->setEquipped(true);
        $character->addInventoryItem($row);

        return $row;
    }

    public function testEffectiveMaxHpIsBasePlusEquippedBonus(): void
    {
        $character = $this->makeCharacter(baseHp: 30);
        self::assertSame(30, $character->getEffectiveMaxHp());
        self::assertSame(30, $character->getMaxHp(), 'the raw getter must stay unaffected by equipped items');

        $item = new Item('Амулет живучести', 0, ItemType::Armor, ItemEffectType::IncreaseMaxHp);
        $item->setMaxHpBonus(20);
        $this->equip($character, $item);

        self::assertSame(50, $character->getEffectiveMaxHp());
        self::assertSame(30, $character->getMaxHp(), 'the raw base must never be mutated by equipping');
    }

    public function testUnequippedItemsDoNotContributeToEffectiveMaxHp(): void
    {
        $character = $this->makeCharacter(baseHp: 30);
        $item = new Item('Амулет живучести', 0, ItemType::Armor, ItemEffectType::IncreaseMaxHp);
        $item->setMaxHpBonus(20);
        $row = new CharacterInventoryItem($character, $item);
        $character->addInventoryItem($row); // not equipped

        self::assertSame(30, $character->getEffectiveMaxHp());
    }

    public function testEffectiveMaxEnergyIsBasePlusEquippedBonus(): void
    {
        $character = $this->makeCharacter(baseEnergy: 10);
        $item = new Item('Кольцо бодрости', 0, ItemType::Shield, ItemEffectType::IncreaseMaxEnergy);
        $item->setMaxEnergyBonus(5);
        $this->equip($character, $item);

        self::assertSame(15, $character->getEffectiveMaxEnergy());
        self::assertSame(10, $character->getMaxEnergy());
    }

    public function testMultipleEquippedItemsOfDifferentTypesStackTheSameBonus(): void
    {
        // Only one item per TYPE can be equipped at once, but IncreaseMaxHp
        // isn't restricted to a single type — two different-typed items
        // (weapon + armor here) can both carry it and both be equipped
        // simultaneously, so both bonuses must apply.
        $character = $this->makeCharacter(baseHp: 30);
        $weapon = new Item('Меч жизни', 0, ItemType::Weapon, ItemEffectType::IncreaseMaxHp);
        $weapon->setMaxHpBonus(10);
        $armor = new Item('Доспех жизни', 0, ItemType::Armor, ItemEffectType::IncreaseMaxHp);
        $armor->setMaxHpBonus(15);
        $this->equip($character, $weapon);
        $this->equip($character, $armor);

        self::assertSame(55, $character->getEffectiveMaxHp());
    }

    public function testInventoryCapacitySumsAcrossMultipleEquippedIncreaseCapacityItems(): void
    {
        $character = $this->makeCharacter();
        self::assertSame(12, $character->getInventoryCapacity());

        $bag = new Item('Сумка', 0, ItemType::Bag, ItemEffectType::IncreaseCapacity);
        $bag->setCapacityBonus(4);
        $otherSlot = new Item('Пояс', 0, ItemType::Shield, ItemEffectType::IncreaseCapacity);
        $otherSlot->setCapacityBonus(2);
        $this->equip($character, $bag);
        $this->equip($character, $otherSlot);

        self::assertSame(18, $character->getInventoryCapacity());
    }

    public function testSetHpClampsAgainstTheEffectiveMaxNotTheRawBase(): void
    {
        $character = $this->makeCharacter(baseHp: 30);
        $item = new Item('Амулет живучести', 0, ItemType::Armor, ItemEffectType::IncreaseMaxHp);
        $item->setMaxHpBonus(20);
        $this->equip($character, $item);

        $character->setHp(45);
        self::assertSame(45, $character->getHp(), 'must be allowed past the raw base (30) since the effective max is 50');

        $character->setHp(999);
        self::assertSame(50, $character->getHp(), 'must still clamp at the effective max');
    }

    public function testSetEnergyClampsAgainstTheEffectiveMax(): void
    {
        $character = $this->makeCharacter(baseEnergy: 10);
        $item = new Item('Кольцо бодрости', 0, ItemType::Shield, ItemEffectType::IncreaseMaxEnergy);
        $item->setMaxEnergyBonus(5);
        $this->equip($character, $item);

        $character->setEnergy(999);
        self::assertSame(15, $character->getEnergy());
    }
}
