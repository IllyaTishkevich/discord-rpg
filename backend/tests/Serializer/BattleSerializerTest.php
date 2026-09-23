<?php

namespace App\Tests\Serializer;

use App\Entity\Battle;
use App\Entity\BattleRound;
use App\Entity\Character;
use App\Entity\CharacterClass;
use App\Entity\User;
use App\Serializer\BattleSerializer;
use PHPUnit\Framework\TestCase;

class BattleSerializerTest extends TestCase
{
    private function makeBattle(): Battle
    {
        $user = new User('discord-id', 'Tester');
        $class = new CharacterClass('warrior', 'Воин', 30, 10);
        $character = new Character($user, $class);

        return new Battle($character, 'Голем', 20);
    }

    public function testRoundIncludesDroppedItems(): void
    {
        $round = new BattleRound($this->makeBattle(), 1, ['attack'], ['defense'], 3, 0, [], [
            ['name' => 'Зелье лечения', 'iconName' => 'potion.png'],
        ]);

        $result = (new BattleSerializer())->round($round);

        self::assertSame([['name' => 'Зелье лечения', 'iconName' => 'potion.png']], $result['itemsDropped']);
    }

    public function testRoundWithNoDropsHasAnEmptyItemsDroppedArray(): void
    {
        $round = new BattleRound($this->makeBattle(), 1, ['attack'], ['defense'], 3, 0);

        $result = (new BattleSerializer())->round($round);

        self::assertSame([], $result['itemsDropped']);
    }

    public function testRoundForViewerIncludesDroppedItemsRegardlessOfPerspective(): void
    {
        $round = new BattleRound($this->makeBattle(), 1, ['attack'], ['defense'], 3, 0, [], [
            ['name' => 'Зелье лечения', 'iconName' => null],
        ]);
        $serializer = new BattleSerializer();

        self::assertSame([['name' => 'Зелье лечения', 'iconName' => null]], $serializer->roundForViewer($round, false)['itemsDropped']);
        self::assertSame([['name' => 'Зелье лечения', 'iconName' => null]], $serializer->roundForViewer($round, true)['itemsDropped']);
    }
}
