<?php

namespace App\Tests\Controller;

use App\Entity\Character;
use App\Entity\CharacterClass;
use App\Entity\User;
use App\Service\BattleService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression coverage for the Activity-native "Начать дуэль" flow
 * (BattleController::createPvp()) — the JWT-authed equivalent of the bot's
 * `/duel` command (BotPvpController::create()), used by the in-Activity
 * voice-channel duel picker. Also exercises BattleService::notifyDuelChallenge()
 * against a real (here, unreachable) BOT_INTERNAL_URL, the same way
 * BattleControllerRealtimeRelayTest does for publishPvpUpdate() — a DM
 * failing must never break challenge creation.
 */
class BattleControllerCreatePvpTest extends WebTestCase
{
    private function makeUserWithCharacter(EntityManagerInterface $em, CharacterClass $class, string $discordId, string $displayName): User
    {
        $user = new User($discordId, $displayName);
        $character = new Character($user, $class);
        $user->setCharacter($character);
        $em->persist($user);
        $em->persist($character);

        return $user;
    }

    public function testCreatingADuelChallengeSucceedsAndSurvivesAnUnreachableBotNotification(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $class = new CharacterClass('create_pvp_'.uniqid(), 'Fixture Class', 20, 10);
        $em->persist($class);
        $challengerUser = $this->makeUserWithCharacter($em, $class, 'cpv-a-'.uniqid(), 'Challenger');
        $opponentUser = $this->makeUserWithCharacter($em, $class, 'cpv-b-'.uniqid(), 'Opponent');
        $em->flush();

        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $challengerAuth = ['HTTP_AUTHORIZATION' => 'Bearer '.$jwtManager->create($challengerUser)];

        $client->request('POST', '/api/battles/pvp', [], [], $challengerAuth, json_encode(['opponentDiscordId' => $opponentUser->getDiscordId()]));

        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('waiting', $body['status']);
        self::assertTrue($body['youAreChallenger']);
        self::assertFalse($body['opponentAccepted']);
    }

    public function testCreatingADuelChallengeAgainstSomeoneWithoutACharacterReturns404(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $class = new CharacterClass('create_pvp_'.uniqid(), 'Fixture Class', 20, 10);
        $em->persist($class);
        $challengerUser = $this->makeUserWithCharacter($em, $class, 'cpv-c-'.uniqid(), 'Challenger');
        $em->flush();

        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $challengerAuth = ['HTTP_AUTHORIZATION' => 'Bearer '.$jwtManager->create($challengerUser)];

        $client->request('POST', '/api/battles/pvp', [], [], $challengerAuth, json_encode(['opponentDiscordId' => 'no-such-discord-id']));

        self::assertResponseStatusCodeSame(404);
    }

    public function testCreatingADuelChallengeAgainstYourselfReturns400(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $class = new CharacterClass('create_pvp_'.uniqid(), 'Fixture Class', 20, 10);
        $em->persist($class);
        $challengerUser = $this->makeUserWithCharacter($em, $class, 'cpv-d-'.uniqid(), 'Challenger');
        $em->flush();

        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $challengerAuth = ['HTTP_AUTHORIZATION' => 'Bearer '.$jwtManager->create($challengerUser)];

        $client->request('POST', '/api/battles/pvp', [], [], $challengerAuth, json_encode(['opponentDiscordId' => $challengerUser->getDiscordId()]));

        self::assertResponseStatusCodeSame(400);
    }

    public function testCreatingASecondDuelChallengeWhileOneIsAlreadyPendingReturns409(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $class = new CharacterClass('create_pvp_'.uniqid(), 'Fixture Class', 20, 10);
        $em->persist($class);
        $challengerUser = $this->makeUserWithCharacter($em, $class, 'cpv-e-'.uniqid(), 'Challenger');
        $opponentUser = $this->makeUserWithCharacter($em, $class, 'cpv-f-'.uniqid(), 'Opponent');
        $thirdUser = $this->makeUserWithCharacter($em, $class, 'cpv-g-'.uniqid(), 'Third');
        $em->flush();

        $battleService = static::getContainer()->get(BattleService::class);
        $battleService->createPvpChallenge($challengerUser->getCharacter(), $opponentUser->getCharacter());

        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $challengerAuth = ['HTTP_AUTHORIZATION' => 'Bearer '.$jwtManager->create($challengerUser)];

        // The challenger already has a pending challenge against the
        // opponent — trying to start a *different* one (against a third
        // player) must also be rejected, not just a repeat of the same pair.
        $client->request('POST', '/api/battles/pvp', [], [], $challengerAuth, json_encode(['opponentDiscordId' => $thirdUser->getDiscordId()]));

        self::assertResponseStatusCodeSame(409);
    }

    public function testCreatingADuelChallengeAgainstSomeoneAlreadyInOneReturns409(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $class = new CharacterClass('create_pvp_'.uniqid(), 'Fixture Class', 20, 10);
        $em->persist($class);
        $opponentUser = $this->makeUserWithCharacter($em, $class, 'cpv-h-'.uniqid(), 'Busy Opponent');
        $otherChallengerUser = $this->makeUserWithCharacter($em, $class, 'cpv-i-'.uniqid(), 'Other Challenger');
        $newChallengerUser = $this->makeUserWithCharacter($em, $class, 'cpv-j-'.uniqid(), 'New Challenger');
        $em->flush();

        $battleService = static::getContainer()->get(BattleService::class);
        $battleService->createPvpChallenge($otherChallengerUser->getCharacter(), $opponentUser->getCharacter());

        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $newChallengerAuth = ['HTTP_AUTHORIZATION' => 'Bearer '.$jwtManager->create($newChallengerUser)];

        $client->request('POST', '/api/battles/pvp', [], [], $newChallengerAuth, json_encode(['opponentDiscordId' => $opponentUser->getDiscordId()]));

        self::assertResponseStatusCodeSame(409);
    }
}
