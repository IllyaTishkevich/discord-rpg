<?php

namespace App\Controller;

use App\Entity\Battle;
use App\Exception\InvalidBattleStateException;
use App\Repository\UserRepository;
use App\Service\BattleService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Backs the bot's /duel command. The Battle row created by `pvp` (status
 * `waiting`) *is* the invite — see docs/BATTLE_ROOM_DESIGN.md.
 */
#[Route('/api/bot/battles')]
class BotPvpController extends AbstractBotController
{
    public function __construct(
        #[Autowire(env: 'BOT_API_SECRET')] string $botApiSecret,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct($botApiSecret);
    }

    #[Route('/pvp', name: 'bot_battle_pvp_create', methods: ['POST'])]
    public function create(Request $request, BattleService $battleService): JsonResponse
    {
        if ($forbidden = $this->checkSecret($request)) {
            return $forbidden;
        }

        $body = $this->decodeJson($request);
        $challengerDiscordId = $body['challengerDiscordId'] ?? null;
        $opponentDiscordId = $body['opponentDiscordId'] ?? null;
        if (!\is_string($challengerDiscordId) || !\is_string($opponentDiscordId)) {
            return $this->json(['error' => 'Missing "challengerDiscordId" or "opponentDiscordId".'], 400);
        }

        $challenger = $this->userRepository->findOneByDiscordId($challengerDiscordId)?->getCharacter();
        $opponent = $this->userRepository->findOneByDiscordId($opponentDiscordId)?->getCharacter();
        if (null === $challenger || null === $opponent) {
            return $this->json(['error' => 'Both players need a character first — open Activity (/play) to create one.'], 404);
        }

        $battle = $battleService->createPvpChallenge($challenger, $opponent);

        return $this->json(['id' => $battle->getId()], 201);
    }

    #[Route('/{id}/accept', name: 'bot_battle_pvp_accept', methods: ['POST'])]
    public function accept(Battle $battle, Request $request, BattleService $battleService): JsonResponse
    {
        if ($forbidden = $this->checkSecret($request)) {
            return $forbidden;
        }

        try {
            $battleService->acceptPvpChallenge($battle);
        } catch (InvalidBattleStateException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        return $this->json(['id' => $battle->getId()]);
    }

    #[Route('/{id}/decline', name: 'bot_battle_pvp_decline', methods: ['POST'])]
    public function decline(Battle $battle, Request $request, BattleService $battleService): JsonResponse
    {
        if ($forbidden = $this->checkSecret($request)) {
            return $forbidden;
        }

        try {
            $battleService->declinePvpChallenge($battle);
        } catch (InvalidBattleStateException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        return $this->json(['id' => $battle->getId()]);
    }
}
