<?php

namespace App\Tests\Service;

use App\Entity\Character;
use App\Entity\CharacterClass;
use App\Entity\Item;
use App\Entity\Monster;
use App\Entity\User;
use App\Enum\BitFace;
use App\Enum\ItemEffectType;
use App\Enum\ItemType;
use App\Service\LootService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class LootServiceTest extends TestCase
{
    private function makeCharacter(): Character
    {
        $user = new User('discord-id', 'Tester');
        $class = new CharacterClass('warrior', 'Воин', 30, 10);

        return new Character($user, $class);
    }

    private function makeItem(string $name = 'Зелье лечения'): Item
    {
        $item = new Item($name, 10, ItemType::Potion, ItemEffectType::Heal);
        $item->setHealAmount(5);

        return $item;
    }

    public function testNoMonsterMeansNoDrops(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $lootService = new LootService($entityManager);

        $drops = $lootService->rollDrops($this->makeCharacter(), null);

        self::assertSame([], $drops);
    }

    public function testEachDropSlotIsRolledIndependently(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $itemA = $this->makeItem('A');
        $itemB = $this->makeItem('B');
        $itemC = $this->makeItem('C');
        $monster = new Monster('Голем', 1, 20);
        $monster->setDropItem1($itemA)->setDropChance1(30);
        $monster->setDropItem2($itemB)->setDropChance2(10);
        $monster->setDropItem3($itemC)->setDropChance3(90);

        // Only the chances given to slots 1 and 3 (30, 90) "succeed" —
        // slot 2's 10% roll fails — confirming each slot is evaluated on
        // its own chance value, not a single shared roll or weighted pick.
        $rollChance = static fn (int $chance): bool => \in_array($chance, [30, 90], true);
        $lootService = new LootService($entityManager, $rollChance);

        $character = $this->makeCharacter();
        $drops = $lootService->rollDrops($character, $monster);

        self::assertSame([$itemA, $itemC], $drops);
        self::assertCount(2, $character->getInventoryItems());
    }

    public function testNoDropsAtAllWhenEveryRollFails(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $monster = new Monster('Голем', 1, 20);
        $monster->setDropItem1($this->makeItem())->setDropChance1(50);

        $lootService = new LootService($entityManager, static fn (): bool => false);

        $drops = $lootService->rollDrops($this->makeCharacter(), $monster);

        self::assertSame([], $drops);
    }

    public function testStopsGrantingOnceInventoryIsFull(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist');
        $entityManager->method('flush');

        $monster = new Monster('Голем', 1, 20);
        $monster->setDropItem1($this->makeItem('A'))->setDropChance1(100);
        $monster->setDropItem2($this->makeItem('B'))->setDropChance2(100);

        $lootService = new LootService($entityManager, static fn (): bool => true);
        $character = $this->makeCharacter();

        // Fill the character's 12-cell inventory up front with unrelated
        // items so it's already full before any drop is rolled.
        for ($i = 0; $i < 12; ++$i) {
            $lootService->rollDrops($character, (new Monster('filler', 1, 1))->setDropItem1($this->makeItem("filler-$i"))->setDropChance1(100));
        }
        self::assertCount(12, $character->getInventoryItems());

        $drops = $lootService->rollDrops($character, $monster);

        self::assertSame([], $drops, 'a full inventory must not receive any more drops');
        self::assertCount(12, $character->getInventoryItems());
    }

    public function testAddBitItemGrantsAConcreteBitOnDrop(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist');
        $entityManager->method('flush');

        $item = new Item('Меч', 20, ItemType::Weapon, ItemEffectType::AddBit);
        $item->setBitFaces(BitFace::Attack, BitFace::Defense, multiplierA: 2);

        $monster = new Monster('Голем', 1, 20);
        $monster->setDropItem1($item)->setDropChance1(100);

        $lootService = new LootService($entityManager, static fn (): bool => true);
        $character = $this->makeCharacter();
        $lootService->rollDrops($character, $monster);

        $row = $character->getInventoryItems()->first();
        self::assertNotNull($row->getGrantedBit(), 'an AddBit item must create a concrete Bit at drop time');
        self::assertSame(BitFace::Attack, $row->getGrantedBit()->getFaceA());
        self::assertSame(2, $row->getGrantedBit()->getMultiplierA());
        self::assertFalse($row->isEquipped(), 'a freshly-dropped item starts unequipped');
    }
}
