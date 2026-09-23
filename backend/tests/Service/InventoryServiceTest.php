<?php

namespace App\Tests\Service;

use App\Entity\Bit;
use App\Entity\Character;
use App\Entity\CharacterClass;
use App\Entity\CharacterInventoryItem;
use App\Entity\Item;
use App\Entity\User;
use App\Enum\BitFace;
use App\Enum\ItemEffectType;
use App\Enum\ItemType;
use App\Exception\InsufficientCoinsException;
use App\Exception\InventoryFullException;
use App\Exception\ItemNotOwnedException;
use App\Service\InventoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class InventoryServiceTest extends TestCase
{
    private InventoryService $inventoryService;

    protected function setUp(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist');
        $entityManager->method('remove');
        $entityManager->method('flush');
        $this->inventoryService = new InventoryService($entityManager);
    }

    private function makeCharacter(): Character
    {
        $user = new User('discord-id', 'Tester');
        $class = new CharacterClass('warrior', 'Воин', 30, 10);

        return new Character($user, $class);
    }

    private function addRow(Character $character, Item $item): CharacterInventoryItem
    {
        $row = new CharacterInventoryItem($character, $item);
        $character->addInventoryItem($row);

        return $row;
    }

    public function testUsingAnEquippableItemEquipsIt(): void
    {
        $character = $this->makeCharacter();
        $sword = new Item('Меч', 20, ItemType::Weapon, ItemEffectType::AddBit);
        $sword->setBitFaces(BitFace::Attack, BitFace::Defense);
        $row = $this->addRow($character, $sword);

        $this->inventoryService->useItem($character, $row);

        self::assertTrue($row->isEquipped());
    }

    public function testUsingASecondItemOfTheSameTypeAutoSwapsTheFirstOne(): void
    {
        $character = $this->makeCharacter();
        $swordA = $this->addRow($character, new Item('Меч А', 20, ItemType::Weapon, ItemEffectType::AddBit));
        $swordB = $this->addRow($character, new Item('Меч Б', 20, ItemType::Weapon, ItemEffectType::AddBit));

        $this->inventoryService->useItem($character, $swordA);
        self::assertTrue($swordA->isEquipped());

        $this->inventoryService->useItem($character, $swordB);

        self::assertFalse($swordA->isEquipped(), 'equipping a second weapon must auto-swap off the first');
        self::assertTrue($swordB->isEquipped());
    }

    public function testEquippingADifferentTypeDoesNotAffectAnAlreadyEquippedOne(): void
    {
        $character = $this->makeCharacter();
        $sword = $this->addRow($character, new Item('Меч', 20, ItemType::Weapon, ItemEffectType::AddBit));
        $shield = $this->addRow($character, new Item('Щит', 20, ItemType::Shield, ItemEffectType::AddBit));

        $this->inventoryService->useItem($character, $sword);
        $this->inventoryService->useItem($character, $shield);

        self::assertTrue($sword->isEquipped(), 'a different item type must not be swapped out');
        self::assertTrue($shield->isEquipped());
    }

    public function testEquippedAddBitItemContributesToGetAllBits(): void
    {
        $character = $this->makeCharacter();
        $item = new Item('Меч', 20, ItemType::Weapon, ItemEffectType::AddBit);
        $item->setBitFaces(BitFace::Attack, BitFace::Defense);
        $row = $this->addRow($character, $item);
        $row->setGrantedBit(new Bit(BitFace::Attack, BitFace::Defense));

        self::assertCount(0, array_filter($character->getAllBits(), fn ($b) => $b === $row->getGrantedBit()), 'unequipped item must not contribute a bit');

        $this->inventoryService->useItem($character, $row);

        self::assertContains($row->getGrantedBit(), $character->getAllBits());
    }

    public function testEquippedBagIncreasesInventoryCapacity(): void
    {
        $character = $this->makeCharacter();
        self::assertSame(12, $character->getInventoryCapacity());

        $bag = new Item('Сумка', 20, ItemType::Bag, ItemEffectType::IncreaseCapacity);
        $bag->setCapacityBonus(6);
        $row = $this->addRow($character, $bag);

        $this->inventoryService->useItem($character, $row);

        self::assertSame(18, $character->getInventoryCapacity());
    }

    public function testUsingAPotionAppliesItsEffectAndDestroysIt(): void
    {
        $character = $this->makeCharacter();
        $character->setHp(10); // maxHp is 30, so this leaves room to heal
        $potion = new Item('Зелье', 10, ItemType::Potion, ItemEffectType::Heal);
        $potion->setHealAmount(5);
        $row = $this->addRow($character, $potion);

        $this->inventoryService->useItem($character, $row);

        self::assertSame(15, $character->getHp());
        self::assertCount(0, $character->getInventoryItems(), 'a used consumable must be removed from the inventory');
    }

    public function testUsingAScrollGrantsXpAndIsDestroyed(): void
    {
        $character = $this->makeCharacter();
        $scroll = new Item('Свиток', 10, ItemType::Scroll, ItemEffectType::GrantXp);
        $scroll->setXpAmount(50);
        $row = $this->addRow($character, $scroll);

        $this->inventoryService->useItem($character, $row);

        self::assertSame(50, $character->getXp());
        self::assertCount(0, $character->getInventoryItems());
    }

    public function testSellingRefundsHalfThePriceAndRemovesTheRow(): void
    {
        $character = $this->makeCharacter();
        $item = new Item('Меч', 21, ItemType::Weapon, ItemEffectType::AddBit);
        $row = $this->addRow($character, $item);

        $refund = $this->inventoryService->sellItem($character, $row);

        self::assertSame(10, $refund, 'intdiv(21, 2) === 10');
        self::assertSame(10, $character->getCoins());
        self::assertCount(0, $character->getInventoryItems());
    }

    public function testDiscardingRemovesTheRowWithoutAnyRefund(): void
    {
        $character = $this->makeCharacter();
        $item = new Item('Меч', 100, ItemType::Weapon, ItemEffectType::AddBit);
        $row = $this->addRow($character, $item);

        $this->inventoryService->discardItem($character, $row);

        self::assertSame(0, $character->getCoins());
        self::assertCount(0, $character->getInventoryItems());
    }

    public function testActingOnAnotherCharactersItemThrows(): void
    {
        $owner = $this->makeCharacter();
        $stranger = $this->makeCharacter();
        $item = new Item('Меч', 20, ItemType::Weapon, ItemEffectType::AddBit);
        $row = $this->addRow($owner, $item);

        $this->expectException(ItemNotOwnedException::class);
        $this->inventoryService->useItem($stranger, $row);
    }

    public function testPurchasingAnItemSpendsCoinsAndGrantsIt(): void
    {
        $character = $this->makeCharacter();
        $character->addCoins(50);
        $item = new Item('Меч', 20, ItemType::Weapon, ItemEffectType::AddBit);
        $item->setBitFaces(BitFace::Attack, BitFace::Defense);

        $row = $this->inventoryService->purchaseItem($character, $item);

        self::assertSame(30, $character->getCoins());
        self::assertSame($item, $row->getItem());
        self::assertFalse($row->isEquipped(), 'a freshly-bought item starts unequipped');
        self::assertCount(1, $character->getInventoryItems());
    }

    public function testPurchasingWithoutEnoughCoinsThrowsAndChargesNothing(): void
    {
        $character = $this->makeCharacter();
        $character->addCoins(5);
        $item = new Item('Меч', 20, ItemType::Weapon, ItemEffectType::AddBit);

        $this->expectException(InsufficientCoinsException::class);
        try {
            $this->inventoryService->purchaseItem($character, $item);
        } finally {
            self::assertSame(5, $character->getCoins());
            self::assertCount(0, $character->getInventoryItems());
        }
    }

    public function testPurchasingWithAFullInventoryThrows(): void
    {
        $character = $this->makeCharacter();
        $character->addCoins(1000);
        for ($i = 0; $i < 12; ++$i) {
            $this->addRow($character, new Item("Filler $i", 1, ItemType::Weapon, ItemEffectType::AddBit));
        }

        $this->expectException(InventoryFullException::class);
        $this->inventoryService->purchaseItem($character, new Item('Меч', 20, ItemType::Weapon, ItemEffectType::AddBit));
    }
}
