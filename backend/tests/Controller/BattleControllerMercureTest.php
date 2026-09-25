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
 * Regression coverage for real-time PvP sync (BattleService::publishPvpUpdate()/
 * BattleController::attachMercureSubscriberCookie()): exercises the actual
 * wired-up Mercure hub end to end — a unit test mocking HubInterface would
 * never have caught the protocol-version/JWT-claims mismatch this setup
 * actually hit against a real hub (see docker-compose.yml's image pin).
 */
class BattleControllerMercureTest extends WebTestCase
{
    public function testJoiningAPvpDuelSetsTheMercureSubscriberCookieAndPublishesWithoutError(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $class = new CharacterClass('mercure_'.uniqid(), 'Fixture Class', 20, 10);
        $em->persist($class);

        $challengerUser = new User('mrc-a-'.uniqid(), 'Challenger');
        $challenger = new Character($challengerUser, $class);
        $challengerUser->setCharacter($challenger);
        $em->persist($challengerUser);
        $em->persist($challenger);

        $opponentUser = new User('mrc-b-'.uniqid(), 'Opponent');
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

        // Opponent accepts and readies up first.
        $client->request('POST', \sprintf('/api/battles/%d/join', $battle->getId()), [], [], $opponentAuth);
        self::assertResponseIsSuccessful();
        self::assertNotNull(
            $client->getResponse()->headers->getCookies()[0] ?? null,
            'joining a PvP battle must set the Mercure subscriber cookie',
        );

        // Challenger readies up too — both sides ready flips the battle to
        // in_progress and throws the first round automatically
        // (BattleService::markReady()), which is where publishPvpUpdate()
        // first fires for real, against the actual configured hub.
        $client->request('POST', \sprintf('/api/battles/%d/join', $battle->getId()), [], [], $challengerAuth);
        self::assertResponseIsSuccessful();

        $cookies = $client->getResponse()->headers->getCookies();
        self::assertNotEmpty($cookies, 'the second join response must also carry a fresh subscriber cookie');
        // A signed JWT is always header.payload.signature — three non-empty
        // base64url segments, not just some placeholder string.
        self::assertMatchesRegularExpression('/^[\w-]+\.[\w-]+\.[\w-]+$/', (string) $cookies[0]->getValue());
    }
}
