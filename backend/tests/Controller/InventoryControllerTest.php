<?php

namespace App\Tests\Controller;

use App\Entity\Character;
use App\Entity\CharacterClass;
use App\Entity\CharacterInventoryItem;
use App\Entity\Item;
use App\Entity\User;
use App\Enum\ItemEffectType;
use App\Enum\ItemType;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression coverage for a real bug: Character::removeInventoryItem()
 * (via ArrayCollection::removeElement()) unsets the removed row's key in
 * place instead of reindexing the collection, so a row removed from
 * anywhere but the very end leaves a gap in the integer keys. PHP's
 * json_encode() serializes an array whose keys aren't a clean 0..n-1
 * sequence as a JSON *object*, not an array — and the Activity's frontend
 * treats InventoryActionResult.items as a real array (`.some()`, `.map()`),
 * so using/selling/discarding anything but the last inventory row crashed
 * the whole screen. Fixed by array_values()-ing before serializing.
 */
class InventoryControllerTest extends WebTestCase
{
    public function testUsingAMiddleInventoryItemReturnsAJsonArrayNotObject(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $class = new CharacterClass('inv_test_class_'.uniqid(), 'Fixture Class', 20, 10);
        $em->persist($class);
        $user = new User('inv-test-'.uniqid(), 'Inventory Tester');
        $character = new Character($user, $class);
        $user->setCharacter($character);
        $em->persist($user);
        $em->persist($character);

        $rows = [];
        foreach (['Первый', 'Второй', 'Зелье', 'Четвёртый'] as $name) {
            $isPotion = 'Зелье' === $name;
            $item = new Item($name, 10, $isPotion ? ItemType::Potion : ItemType::Scroll, $isPotion ? ItemEffectType::Heal : ItemEffectType::GrantXp);
            $isPotion ? $item->setHealAmount(1) : $item->setXpAmount(1);
            $em->persist($item);

            $row = new CharacterInventoryItem($character, $item);
            $character->addInventoryItem($row);
            $em->persist($row);
            $rows[] = $row;
        }
        $em->flush();

        $middleRow = $rows[2]; // "Зелье" — a middle row, neither first nor last

        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $token = $jwtManager->create($user);

        $client->request('POST', \sprintf('/api/inventory/%d/use', $middleRow->getId()), [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body['items']);
        self::assertTrue(array_is_list($body['items']), 'items must decode as a JSON array, not an object with gapped keys');
        self::assertCount(3, $body['items']);
    }
}
