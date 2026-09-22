<?php

namespace App\Controller;

use App\Repository\UserRepository;
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
    public function __construct(
        #[Autowire(env: 'BOT_API_SECRET')] private readonly string $botApiSecret,
    ) {
    }

    #[Route('/characters/{discordId}', name: 'bot_character_profile', methods: ['GET'])]
    public function profile(string $discordId, Request $request, UserRepository $userRepository): JsonResponse
    {
        if (!hash_equals($this->botApiSecret, (string) $request->headers->get('X-Bot-Secret'))) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $user = $userRepository->findOneByDiscordId($discordId);
        $character = $user?->getCharacter();
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
}
