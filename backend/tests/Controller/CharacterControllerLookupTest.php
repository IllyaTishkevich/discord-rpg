<?php

namespace App\Tests\Controller;

use App\Entity\Character;
use App\Entity\CharacterClass;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression coverage for CharacterController::lookupByDiscordIds() — turns
 * the Discord SDK's voice-channel participant id list into actual duel
 * candidates for the Activity's "Начать дуэль" picker.
 */
class CharacterControllerLookupTest extends WebTestCase
{
    public function testLookupReturnsMatchingCharactersAndExcludesTheCallerThemselves(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $class = new CharacterClass('lookup_'.uniqid(), 'Воин', 20, 10);
        $em->persist($class);

        $me = new User('lkp-me-'.uniqid(), 'Me');
        $meCharacter = new Character($me, $class);
        $me->setCharacter($meCharacter);
        $em->persist($me);
        $em->persist($meCharacter);

        $other = new User('lkp-other-'.uniqid(), 'Other');
        $otherCharacter = new Character($other, $class);
        $other->setCharacter($otherCharacter);
        $em->persist($other);
        $em->persist($otherCharacter);

        // A real Discord user with no character yet — must not appear.
        $noCharacterUser = new User('lkp-none-'.uniqid(), 'No Character');
        $em->persist($noCharacterUser);

        $em->flush();

        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$jwtManager->create($me)];

        $client->request(
            'POST',
            '/api/characters/by-discord-ids',
            [],
            [],
            $auth,
            json_encode(['discordIds' => [$me->getDiscordId(), $other->getDiscordId(), $noCharacterUser->getDiscordId(), 'not-a-real-discord-id']]),
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertCount(1, $body, 'only the other real character should be returned — never the caller, never a characterless user, never an unknown id');
        self::assertSame($other->getDiscordId(), $body[0]['discordId']);
        self::assertSame('Other', $body[0]['displayName']);
        self::assertSame('Воин', $body[0]['className']);
    }

    public function testLookupWithoutDiscordIdsReturns400(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $class = new CharacterClass('lookup_'.uniqid(), 'Воин', 20, 10);
        $em->persist($class);
        $me = new User('lkp-bad-'.uniqid(), 'Me');
        $meCharacter = new Character($me, $class);
        $me->setCharacter($meCharacter);
        $em->persist($me);
        $em->persist($meCharacter);
        $em->flush();

        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$jwtManager->create($me)];

        $client->request('POST', '/api/characters/by-discord-ids', [], [], $auth, json_encode([]));

        self::assertResponseStatusCodeSame(400);
    }
}
