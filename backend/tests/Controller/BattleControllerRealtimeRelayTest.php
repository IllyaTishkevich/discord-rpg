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
 * Regression coverage for real-time PvP sync (BattleService::publishPvpUpdate()):
 * exercises the actual wired-up HttpClient against WS_RELAY_URL, which in
 * the test environment points at a port nothing is listening on — a unit
 * test mocking HttpClientInterface would never catch a missing try/catch
 * regression here, since the whole point is that a real, unreachable relay
 * must never fail the PvP request that triggered the notification.
 */
class BattleControllerRealtimeRelayTest extends WebTestCase
{
    public function testJoiningAPvpDuelSucceedsEvenThoughTheRealtimeRelayIsUnreachable(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $class = new CharacterClass('relay_'.uniqid(), 'Fixture Class', 20, 10);
        $em->persist($class);

        $challengerUser = new User('rly-a-'.uniqid(), 'Challenger');
        $challenger = new Character($challengerUser, $class);
        $challengerUser->setCharacter($challenger);
        $em->persist($challengerUser);
        $em->persist($challenger);

        $opponentUser = new User('rly-b-'.uniqid(), 'Opponent');
        $opponent = new Character($opponentUser, $class);
        $opponentUser->setCharacter($opponent);
        $em->persist($opponentUser);
        $em->persist($opponent);
        $em->flush();

        $battleService = static::getContainer()->get(BattleService::class);
        $battle = $battleService->createPvpChallenge($challenger, $opponent);

        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $challengerAuth = ['HTTP_AUTHORIZATION' => 'Bearer '.$jwtManager->create($challengerUser)];
        $opponentAuth = ['HTTP_AUTHORIZATION' => 'Bearer '.$jwtManager->create($opponentUser)];

        $client->request('POST', \sprintf('/api/battles/%d/join', $battle->getId()), [], [], $opponentAuth);
        self::assertResponseIsSuccessful();

        // The challenger readying up too flips the battle to in_progress and
        // throws the first round automatically (BattleService::markReady()),
        // which is where publishPvpUpdate() first fires for real, against
        // whatever BOT_INTERNAL_URL actually resolves to in this environment —
        // nothing is listening on it here, so this only passes if the
        // resulting transport exception is genuinely swallowed.
        $client->request('POST', \sprintf('/api/battles/%d/join', $battle->getId()), [], [], $challengerAuth);
        self::assertResponseIsSuccessful();
    }
}
