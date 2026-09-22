<?php

namespace App\Controller;

use App\Enum\BattleStatus;
use App\Exception\BattleAlreadyFinishedException;
use App\Exception\InsufficientEnergyException;
use App\Repository\UserRepository;
use App\Service\BattleService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Service-to-service endpoints used by the Discord bot process, authenticated
 * with a shared secret (BOT_API_SECRET) instead of end-user JWTs — the bot
 * already knows which Discord user it's acting for.
 */
#[Route('/api/bot')]
class BotController extends AbstractApiController
{
    // Safety cap: with the current 3-bit starter loadouts, a round almost
    // always deals damage on one side, but 0-0 draws are possible, so an
    // unbounded auto-play loop isn't safe for a synchronous HTTP request.
    private const MAX_AUTO_ROUNDS = 30;

    public function __construct(
        #[Autowire(env: 'BOT_API_SECRET')] private readonly string $botApiSecret,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[Route('/characters/{discordId}', name: 'bot_character_profile', methods: ['GET'])]
    public function profile(string $discordId, Request $request): JsonResponse
    {
        if ($forbidden = $this->checkSecret($request)) {
            return $forbidden;
        }

        $character = $this->userRepository->findOneByDiscordId($discordId)?->getCharacter();
        if (null === $character) {
            return $this->json(['error' => 'No character for this user yet.'], 404);
        }

        return $this->json([
            'class' => [
                'code' => $character->getCharacterClass()->getCode(),
                'name' => $character->getCharacterClass()->getName(),
            ],
            'hp' => $character->getHp(),
            'maxHp' => $character->getMaxHp(),
            'energy' => $character->getEnergy(),
            'maxEnergy' => $character->getMaxEnergy(),
            'level' => $character->getLevel(),
            'xp' => $character->getXp(),
            'coins' => $character->getCoins(),
        ]);
    }

    /**
     * Plays a full PvE battle to completion with no action-flip choices —
     * the text-only bot command has no per-round UI, unlike the Activity's
     * interactive arena. Spends one energy point just like the Activity flow.
     */
    #[Route('/battles/{discordId}/pve/auto', name: 'bot_battle_auto_pve', methods: ['POST'])]
    public function autoPve(string $discordId, Request $request, BattleService $battleService): JsonResponse
    {
        if ($forbidden = $this->checkSecret($request)) {
            return $forbidden;
        }

        $character = $this->userRepository->findOneByDiscordId($discordId)?->getCharacter();
        if (null === $character) {
            return $this->json(['error' => 'No character for this user yet.'], 404);
        }

        try {
            $battle = $battleService->startPveBattle($character);
        } catch (InsufficientEnergyException) {
            return $this->json(['error' => 'Not enough energy to start a battle.'], 409);
        }

        $rounds = [];
        for ($i = 0; $i < self::MAX_AUTO_ROUNDS && BattleStatus::InProgress === $battle->getStatus(); ++$i) {
            $battleService->throwRound($battle);

            try {
                $round = $battleService->resolveRound($battle, []);
            } catch (BattleAlreadyFinishedException) {
                break;
            }

            $rounds[] = [
                'damageToOpponent' => $round->getDamageToOpponent(),
                'damageToPlayer' => $round->getDamageToPlayer(),
            ];
        }

        return $this->json([
            'status' => $battle->getStatus()->value,
            'roundsPlayed' => \count($rounds),
            'opponent' => [
                'name' => $battle->getOpponentName(),
                'hp' => $battle->getOpponentHp(),
                'maxHp' => $battle->getOpponentMaxHp(),
            ],
            'character' => [
                'hp' => $character->getHp(),
                'maxHp' => $character->getMaxHp(),
            ],
            'rewards' => BattleStatus::Won === $battle->getStatus()
                ? ['xp' => BattleService::XP_REWARD, 'coins' => BattleService::COIN_REWARD]
                : null,
        ]);
    }

    private function checkSecret(Request $request): ?JsonResponse
    {
        if (!hash_equals($this->botApiSecret, (string) $request->headers->get('X-Bot-Secret'))) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        return null;
    }
}
