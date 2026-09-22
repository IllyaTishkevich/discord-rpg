<?php

namespace App\Controller;

use App\Entity\Tournament;
use App\Exception\AlreadyRegisteredException;
use App\Exception\NotEnoughEntriesException;
use App\Repository\UserRepository;
use App\Service\TournamentService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/bot/tournaments')]
class BotTournamentController extends AbstractBotController
{
    public function __construct(
        #[Autowire(env: 'BOT_API_SECRET')] string $botApiSecret,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct($botApiSecret);
    }

    /**
     * Admin-triggered (Discord-side permission-gated): opens registration
     * for a new tournament, or returns the already-open one.
     */
    #[Route('/open', name: 'bot_tournament_open', methods: ['POST'])]
    public function open(Request $request, TournamentService $tournamentService): JsonResponse
    {
        if ($forbidden = $this->checkSecret($request)) {
            return $forbidden;
        }

        $tournament = $tournamentService->openRegistration();

        return $this->json(['id' => $tournament->getId(), 'entryCount' => $tournament->getEntries()->count()], 201);
    }

    #[Route('/{id}/register/{discordId}', name: 'bot_tournament_register', methods: ['POST'])]
    public function register(Tournament $tournament, string $discordId, Request $request, TournamentService $tournamentService): JsonResponse
    {
        if ($forbidden = $this->checkSecret($request)) {
            return $forbidden;
        }

        $character = $this->userRepository->findOneByDiscordId($discordId)?->getCharacter();
        if (null === $character) {
            return $this->json(['error' => 'No character for this user yet.'], 404);
        }

        try {
            $tournamentService->register($tournament, $character);
        } catch (AlreadyRegisteredException) {
            return $this->json(['error' => 'Already registered.'], 409);
        }

        return $this->json(['entryCount' => $tournament->getEntries()->count()], 201);
    }

    /**
     * Admin-triggered: simulates the entire bracket to a champion in one shot.
     */
    #[Route('/{id}/start', name: 'bot_tournament_start', methods: ['POST'])]
    public function start(Tournament $tournament, Request $request, TournamentService $tournamentService): JsonResponse
    {
        if ($forbidden = $this->checkSecret($request)) {
            return $forbidden;
        }

        try {
            $champion = $tournamentService->start($tournament);
        } catch (NotEnoughEntriesException) {
            return $this->json(['error' => 'Not enough entries to start.'], 409);
        }

        return $this->json([
            'championDiscordId' => $champion->getUser()->getDiscordId(),
            'championName' => $champion->getUser()->getDisplayName(),
        ]);
    }
}
